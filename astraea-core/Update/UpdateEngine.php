<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Update;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;
use Astraea\Exceptions\StorageException;
use Astraea\Security\SecurityEventManager;
use Astraea\Security\Logger;
use Astraea\Diagnostics\SecurityProbeManager;
use Astraea\Diagnostics\HealthStatus;
use ZipArchive;

/**
 * Transactional, Fail-Closed Update Engine for AstraeaOS WP.
 *
 * Enforces Ed25519 verification, SHA-256 package checks, pre-update Vault snapshots,
 * ZipSlip-immune path-jailed extraction, atomic file swaps, and automated rollback.
 *
 * @package Astraea\Update
 */
final class UpdateEngine {

    /**
     * Execute full transactional update from verified release manifest and downloaded package.
     *
     * @param ReleaseManifest $manifest
     * @param string $packageZipPath Path to downloaded update ZIP file
     * @param bool $allowDowngrade Only true during recovery rollbacks
     * @return array{status: string, message: string, snapshot_id: ?string}
     * @throws SecurityException
     * @throws ValidationException
     * @throws StorageException
     */
    public static function applyUpdate(ReleaseManifest $manifest, string $packageZipPath, bool $allowDowngrade = false): array {
        // 1. Verify Manifest & Host Environment
        $authenticity = UpdateVerifier::verify($manifest, $allowDowngrade);
        if ($authenticity !== AuthenticityStatus::SIGNED_VERIFIED) {
            throw new SecurityException(sprintf(
                'Update rejected: Release manifest authenticity status "%s" is untrusted. Updates must be cryptographically signed by Astraea Release Authority.',
                $authenticity->value
            ));
        }

        // 2. Package File & SHA-256 Verification
        if (!is_file($packageZipPath) || !is_readable($packageZipPath)) {
            throw new StorageException('Update package file is missing or unreadable.');
        }

        $measuredSha = hash_file('sha256', $packageZipPath);
        if (!is_string($measuredSha) || !hash_equals($manifest->sha256, strtolower($measuredSha))) {
            throw new SecurityException('Update package SHA-256 mismatch. Package rejected.');
        }

        $lockPath = (defined('ABSPATH') ? rtrim(ABSPATH, '/\\') : dirname(__DIR__, 2)) . DIRECTORY_SEPARATOR . '.astraea-update.lock';
        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) fclose($lockHandle);
            throw new StorageException('Another Astraea update transaction is active.');
        }

        $stagingDir = null;
        $backupDir = null;
        $maintenanceFile = null;
        $swapped = false;
        $migrationState = null;
        $preserveMaintenance = false;
        try {
            // 3. Astraea Vault Pre-Update Snapshot
            $snapshotId = self::createPreUpdateSnapshot($manifest->version);

            // 4. Create Isolated Staging Directory
            $stagingDir = self::prepareStagingDirectory();

            // 5. Safe Path-Jailed ZIP Extraction (ZipSlip & Symlink Immunity)
            self::extractPackageSafely($packageZipPath, $stagingDir);

            // 6. Extracted Payload Sanity & Manifest Verification
            self::validateExtractedPayload($stagingDir);

            // 7. Atomic / Safe Swap into Core
            $backupDir = self::prepareBackupDirectory();
            $maintenanceFile = (defined('ABSPATH') ? ABSPATH : dirname(__DIR__, 2) . '/') . '.maintenance';
            if (file_put_contents($maintenanceFile, '<?php $upgrading = ' . time() . '; ?>', LOCK_EX) === false) {
                throw new StorageException('Unable to activate update maintenance mode.');
            }

            self::atomicSwapFiles($stagingDir, $backupDir);
            $swapped = true;

            // 8. Database Migrations
            $migrationState = self::runPendingMigrations();

            // 9. Post-Update Health Verification
            if (!self::verifyPostUpdateHealth()) {
                throw new SecurityException('Post-update health probes failed.');
            }

            self::cleanupDirectory($stagingDir);
            self::cleanupDirectory($backupDir);
            $swapped = false;

            self::logEvent('update_applied_success', sprintf('Updated to version %s successfully.', $manifest->version));

            return [
                'status'      => 'SUCCESS',
                'message'     => sprintf('AstraeaOS updated to %s successfully.', $manifest->version),
                'snapshot_id' => $snapshotId,
            ];

        } catch (\Throwable $e) {
            if (is_array($migrationState) && $migrationState['applied'] !== []) {
                try {
                    $migrationState['runner']->rollbackApplied($migrationState['migrations'], $migrationState['applied']);
                } catch (\Throwable $rollbackFailure) {
                    $preserveMaintenance = true;
                    self::logEvent('database_rollback_failed', 'Database compensation failed; maintenance mode preserved for manual recovery.');
                    throw new StorageException('Database compensation failed; core files were intentionally not rolled back.', 0, $rollbackFailure);
                }
            }
            if ($swapped && is_string($backupDir) && is_dir($backupDir)) {
                self::rollbackFiles($backupDir);
                $swapped = false;
            }
            self::logEvent('update_failed', 'Update execution failed: ' . $e->getMessage());

            // Ensure staging cleanup
            if (is_string($stagingDir) && is_dir($stagingDir)) {
                self::cleanupDirectory($stagingDir);
            }

            if ($e instanceof SecurityException || $e instanceof ValidationException || $e instanceof StorageException) {
                throw $e;
            }
            throw new StorageException('Update failed: ' . $e->getMessage(), 0, $e);
        } finally {
            if (!$preserveMaintenance && is_string($maintenanceFile) && is_file($maintenanceFile)) @unlink($maintenanceFile);
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Trigger Pre-Update Vault Snapshot.
     */
    private static function createPreUpdateSnapshot(string $targetVersion): string {
        if (class_exists('\\Astraea\\Vault\\Plugin')) {
            try {
                $plugin = \Astraea\Vault\Plugin::instance();
                $services = $plugin->services();
                if (isset($services['backup_orchestrator']) && method_exists($services['backup_orchestrator'], 'run')) {
                    $result = $services['backup_orchestrator']->run('pre-update-' . $targetVersion);
                    if (is_array($result) && isset($result['id'])) {
                        $snapshotId = trim((string)$result['id']);
                        if ($snapshotId !== '') return $snapshotId;
                    }
                }
            } catch (\Throwable $e) {
                throw new StorageException('Mandatory pre-update snapshot failed.', 0, $e);
            }
        }
        throw new StorageException('Mandatory pre-update snapshot service is unavailable.');
    }

    /**
     * Prepare an isolated staging directory within the secure temp path.
     */
    private static function prepareStagingDirectory(): string {
        $baseTemp = self::resolveTempDirectory();
        $stagingDir = $baseTemp . DIRECTORY_SEPARATOR . 'update-staging-' . bin2hex(random_bytes(8));
        if (!mkdir($stagingDir, 0700, true)) {
            throw new StorageException('Failed to create update staging directory.');
        }
        return $stagingDir;
    }

    private static function prepareBackupDirectory(): string {
        $baseTemp = self::resolveTempDirectory();
        $backupDir = $baseTemp . DIRECTORY_SEPARATOR . 'update-backup-' . bin2hex(random_bytes(8));
        if (!mkdir($backupDir, 0700, true)) {
            throw new StorageException('Failed to create update backup directory.');
        }
        return $backupDir;
    }

    private static function resolveTempDirectory(): string {
        $dir = defined('WP_CONTENT_DIR')
            ? WP_CONTENT_DIR . '/uploads/vgt-temp'
            : sys_get_temp_dir() . '/astraea-update';

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $resolved = realpath($dir);
        if ($resolved === false || !is_dir($resolved)) {
            throw new StorageException('Unable to resolve secure update temporary directory.');
        }
        return $resolved;
    }

    public const MAX_ARCHIVE_FILES = 5000;
    public const MAX_UNCOMPRESSED_BYTES = 104857600; // 100 MB
    public const MAX_COMPRESSION_RATIO = 100;

    /**
     * Path-jailed extraction rejecting directory traversal (ZipSlip), symlinks, and Zip-bombs.
     */
    public static function extractPackageSafely(string $zipPath, string $destinationDir): void {
        $resolvedDest = realpath($destinationDir);
        if ($resolvedDest === false || !is_dir($resolvedDest)) {
            throw new StorageException('Target extraction destination does not exist.');
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new StorageException(sprintf('Failed to open update archive. Code: %d', $openResult));
        }

        try {
            $numFiles = $zip->numFiles;
            if ($numFiles > self::MAX_ARCHIVE_FILES) {
                throw new SecurityException(sprintf('ZIP archive exceeds maximum file count limit of %d entries.', self::MAX_ARCHIVE_FILES));
            }

            $totalUncompressedBytes = 0;

            for ($i = 0; $i < $numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if (!is_string($entryName) || $entryName === '') {
                    continue;
                }

                // ZipSlip check: reject any entry containing directory traversal sequences
                $normalized = str_replace('\\', '/', $entryName);
                if (
                    str_contains($normalized, '../') ||
                    str_contains($normalized, '/..') ||
                    $normalized === '..' ||
                    str_starts_with($normalized, '/') ||
                    preg_match('/^[a-zA-Z]:/i', $normalized)
                ) {
                    throw new SecurityException(sprintf('ZIP path traversal attempt blocked: "%s".', $entryName));
                }

                // Check entry stats for symlinks and Zip-bomb thresholds
                $stat = $zip->statIndex($i);
                $uncompressed = ($stat !== false && isset($stat['size'])) ? (int)$stat['size'] : 0;
                $compressed = ($stat !== false && isset($stat['comp_size'])) ? (int)$stat['comp_size'] : 0;

                if ($compressed > 0 && ($uncompressed / $compressed) > self::MAX_COMPRESSION_RATIO) {
                    throw new SecurityException(sprintf('ZIP bomb detected: compression ratio for "%s" exceeds %d:1.', $entryName, self::MAX_COMPRESSION_RATIO));
                }

                $totalUncompressedBytes += $uncompressed;
                if ($totalUncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new SecurityException(sprintf('ZIP archive exceeds maximum uncompressed size boundary (%d bytes).', self::MAX_UNCOMPRESSED_BYTES));
                }

                if ($stat !== false && isset($stat['opsys'])) {
                    // Unix symlink detection in zip
                    $attr = $stat['external_attributes'] ?? 0;
                    if (((($attr >> 16) & 0170000) === 0120000)) { // S_IFLNK
                        throw new SecurityException(sprintf('Symlink detected in archive: "%s". Forbidden.', $entryName));
                    }
                }

                $targetPath = $resolvedDest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryName);

                // Ensure target does not escape jail post-construction
                if (!str_starts_with($targetPath, $resolvedDest . DIRECTORY_SEPARATOR)) {
                    throw new SecurityException(sprintf('Path escaped extraction jail: "%s".', $entryName));
                }

                if (str_ends_with($normalized, '/')) {
                    if (!is_dir($targetPath) && !@mkdir($targetPath, 0755, true)) {
                        throw new StorageException(sprintf('Failed to create directory: %s', $targetPath));
                    }
                    continue;
                }

                $parentDir = dirname($targetPath);
                if (!is_dir($parentDir) && !@mkdir($parentDir, 0755, true)) {
                    throw new StorageException(sprintf('Failed to create parent directory: %s', $parentDir));
                }

                $stream = $zip->getStream($entryName);
                if ($stream === false) {
                    throw new StorageException(sprintf('Failed to read entry from ZIP: %s', $entryName));
                }

                $outFile = fopen($targetPath, 'wb');
                if ($outFile === false) {
                    fclose($stream);
                    throw new StorageException(sprintf('Failed to open output file: %s', $targetPath));
                }

                $copied = stream_copy_to_stream($stream, $outFile, $uncompressed + 1);
                fclose($stream);
                fclose($outFile);
                if ($copied === false || $copied !== $uncompressed || filesize($targetPath) !== $uncompressed) {
                    @unlink($targetPath);
                    throw new SecurityException(sprintf('ZIP entry size mismatch detected for "%s".', $entryName));
                }
            }
        } finally {
            $zip->close();
        }
    }

    private static function validateExtractedPayload(string $stagingDir): void {
        $coreDir = $stagingDir . '/astraea-core';
        if (!is_dir($coreDir)) {
            // Check if root of zip is astraea-core or if files are directly inside
            if (!is_file($stagingDir . '/astraea-bootstrap.php') && !is_dir($stagingDir . '/Modules')) {
                throw new ValidationException('Extracted package missing required Astraea core structure.');
            }
        }
    }

    private static function atomicSwapFiles(string $stagingDir, string $backupDir): void {
        $targetCore = defined('ASTRAEA_CORE_DIR') ? rtrim(ASTRAEA_CORE_DIR, '/\\') : dirname(__DIR__);
        $resolvedCore = realpath($targetCore);
        if ($resolvedCore === false) {
            throw new StorageException('Cannot resolve Astraea Core directory for update swap.');
        }

        // Determine source files in staging
        $sourceDir = is_dir($stagingDir . '/astraea-core') ? $stagingDir . '/astraea-core' : $stagingDir;

        if (!@rmdir($backupDir)) {
            throw new StorageException('Update backup destination is not empty.');
        }
        if (!@rename($resolvedCore, $backupDir)) {
            throw new StorageException('Atomic rename of current core into backup failed.');
        }
        if (!@rename($sourceDir, $resolvedCore)) {
            @rename($backupDir, $resolvedCore);
            throw new StorageException('Atomic rename of staged core into service failed.');
        }
    }

    private static function rollbackFiles(string $backupDir): void {
        $targetCore = defined('ASTRAEA_CORE_DIR') ? rtrim(ASTRAEA_CORE_DIR, '/\\') : dirname(__DIR__);
        $resolvedCore = realpath($targetCore);
        if ($resolvedCore !== false && is_dir($backupDir)) {
            $failedDir = dirname($backupDir) . DIRECTORY_SEPARATOR . 'update-failed-' . bin2hex(random_bytes(8));
            if (!@rename($resolvedCore, $failedDir) || !@rename($backupDir, $targetCore)) {
                if (is_dir($failedDir) && !is_dir($targetCore)) @rename($failedDir, $targetCore);
                throw new StorageException('Atomic update rollback failed. Manual recovery is required.');
            }
            self::cleanupDirectory($failedDir);
            self::logEvent('rollback_executed', 'Reverted core files to pre-update state.');
        }
    }

    private static function copyRecursive(string $src, string $dst): void {
        if (!is_dir($src)) {
            return;
        }
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $subPath = substr($item->getPathname(), strlen($src));
            $target = $dst . $subPath;

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private static function cleanupDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /** @return array{runner:\Astraea\Database\MigrationRunner,migrations:array<int,\Astraea\Database\MigrationInterface>,applied:string[]} */
    private static function runPendingMigrations(): array {
        if (!class_exists('\\Astraea\\Database\\MigrationRunner')) {
            throw new StorageException('Database migration service is unavailable.');
        }

        try {
            $runner = new \Astraea\Database\MigrationRunner();
            $migrations = [
                new \Astraea\Database\Migrations\Migration_001_InitialSchema(),
                new \Astraea\Database\Migrations\Migration_002_VLPLight(),
                new \Astraea\Database\Migrations\Migration_003_OperationalHardening(),
            ];
            $results = $runner->runPending($migrations, true);
            $applied = array_keys(array_filter($results, static fn(string $status): bool => $status === 'applied'));
            return ['runner' => $runner, 'migrations' => $migrations, 'applied' => $applied];
        } catch (\Throwable $e) {
            throw new StorageException('Post-update migration failed.', 0, $e);
        }
    }

    private static function verifyPostUpdateHealth(): bool {
        if (!class_exists(SecurityProbeManager::class)) {
            return true;
        }

        $cryptoProbe = SecurityProbeManager::probeZeusCrypto();
        if ($cryptoProbe['status'] === HealthStatus::CRITICAL) {
            return false;
        }

        return true;
    }

    private static function logEvent(string $type, string $message): void {
        if (class_exists(SecurityEventManager::class)) {
            SecurityEventManager::recordOnce(
                SecurityEventManager::SEVERITY_SECURITY,
                'UpdateEngine',
                $type,
                $message,
                [],
                60
            );
        }
        if (class_exists(Logger::class)) {
            Logger::info('[UpdateEngine] ' . $message);
        }
    }
}
