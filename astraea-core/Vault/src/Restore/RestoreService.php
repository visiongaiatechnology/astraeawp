<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Restore;

use Astraea\Vault\Backup\ContainerReader;
use Astraea\Vault\Backup\ContainerWriter;
use Astraea\Vault\Crypto\CryptoService;
use Astraea\Vault\Exception\SecurityException;
use Astraea\Vault\Exception\StorageException;
use Astraea\Vault\Exception\ValidationException;
use Astraea\Vault\Storage\LocalStorage;
use Astraea\Vault\Support\DbLock;

final class RestoreService
{
    public function __construct(
        private readonly LocalStorage $storage,
        private readonly CryptoService $crypto,
        private readonly DatabaseRestorer $database
    ) {}

    public function restore(string $backupId, string $masterKey, string $scope = 'full'): array
    {
        if (!in_array($scope, ['full', 'database', 'files'], true)) {
            throw new ValidationException('Unsupported restore scope.');
        }
        $lock = new DbLock();
        $stage = '';
        try {
            $lock->acquire('astraea_vault_restore', 0);
            $stage = $this->storage->createStaging('restore');
            $staged = $this->stage($backupId, $masterKey, $stage);
            $backupType = (string)($staged['header']['backup_type'] ?? '');
            if (!in_array($backupType, ['full', 'database'], true)) {
                throw new SecurityException('Manual restore backup type validation failed.');
            }
            if ($backupType === 'database') {
                if ($scope === 'files') {
                    throw new ValidationException('A database-only backup cannot restore files.');
                }
                $scope = 'database';
            }
            $result = ['files' => ['files' => 0, 'bytes' => 0], 'database' => ['tables' => 0, 'rows' => 0]];
            if ($scope === 'full' || $scope === 'database') {
                $result['database'] = $this->database->apply($staged['tables']);
            }
            if ($scope === 'full' || $scope === 'files') {
                $result['files'] = $this->applyFiles($staged['files_root'], ABSPATH, null, $staged['file_modes']);
            }
            return $result;
        } finally {
            if ($stage !== '' && is_dir($stage)) {
                try { $this->storage->removeTree($stage); } catch (\Throwable) {}
            }
            $lock->release('astraea_vault_restore');
        }
    }

    public function restorePluginSnapshot(string $backupId, string $masterKey, string $plugin): array
    {
        $plugin = wp_normalize_path($plugin);
        if ($plugin === '' || str_contains($plugin, '..') || str_contains($plugin, "\0")) {
            throw new SecurityException('Plugin identifier validation failed.');
        }
        $dirPart = dirname($plugin);
        $slug = $dirPart === '.' ? '' : basename($dirPart);
        $expectedPrefix = $slug === '' ? basename($plugin) : $slug . '/';
        $stage = '';
        $lock = new DbLock();
        try {
            $lock->acquire('astraea_vault_restore', 0);
            $stage = $this->storage->createStaging('plugin-rollback');
            $staged = $this->stage($backupId, $masterKey, $stage);
            if ((string)($staged['header']['backup_type'] ?? '') !== 'plugin') {
                throw new SecurityException('Plugin rollback backup type validation failed.');
            }
            $manifestPlugin = (string)($staged['manifest']['context']['plugin'] ?? '');
            if ($manifestPlugin === '' || !hash_equals($plugin, wp_normalize_path($manifestPlugin))) {
                throw new SecurityException('Plugin rollback manifest binding validation failed.');
            }
            $files = $this->enumerateRelativeFiles($staged['files_root']);
            if ($files === []) {
                throw new StorageException('Plugin snapshot contains no files.');
            }
            foreach ($files as $rel) {
                $normalized = str_replace('\\', '/', $rel);
                if ($slug === '') {
                    if ($normalized !== $expectedPrefix) {
                        throw new SecurityException('Plugin snapshot scope validation failed.');
                    }
                } elseif (!str_starts_with($normalized, $expectedPrefix)) {
                    throw new SecurityException('Plugin snapshot scope validation failed.');
                }
            }

            $target = $slug === ''
                ? WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . basename($plugin)
                : WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug;
            $quarantine = $this->quarantinePluginTarget($target, $plugin);
            try {
                return $this->applyFiles(
                    $staged['files_root'],
                    WP_PLUGIN_DIR,
                    $slug === '' ? $expectedPrefix : $slug,
                    $staged['file_modes']
                );
            } catch (\Throwable $e) {
                $this->rollbackQuarantine($target, $quarantine);
                throw $e;
            }
        } finally {
            if ($stage !== '' && is_dir($stage)) {
                try { $this->storage->removeTree($stage); } catch (\Throwable) {}
            }
            $lock->release('astraea_vault_restore');
        }
    }

    private function stage(string $backupId, string $masterKey, string $stage): array
    {
        $path = $this->storage->resolveExistingBackup($backupId);
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new StorageException('Unable to open backup for restore.');
        }
        $reader = new ContainerReader($handle, $this->crypto, $masterKey);
        $headerId = (string)($reader->header()['backup_id'] ?? '');
        if (!hash_equals($backupId, $headerId)) {
            $reader->close();
            throw new SecurityException('Restore backup identifier binding validation failed.');
        }
        $filesRoot = $stage . DIRECTORY_SEPARATOR . 'files';
        $dbRoot = $stage . DIRECTORY_SEPARATOR . 'db';
        $oldUmask = umask(0077);
        try {
            if (!mkdir($filesRoot, 0700, true) || !mkdir($dbRoot, 0700, true)) {
                throw new StorageException('Unable to create restore staging directories.');
            }
        } finally {
            umask($oldUmask);
        }

        $tables = [];
        $fileModes = [];
        $finalManifest = [];
        $largeRow = null;
        $currentHandle = null;
        $currentPath = '';
        $currentExpectedSize = 0;
        $currentActualSize = 0;
        $currentHash = null;
        try {
            while (($record = $reader->next()) !== null) {
                $type = (int)$record['type'];
                $payload = (string)$record['payload'];
                if ($type === ContainerWriter::TYPE_HEADER_AUTH) {
                    continue;
                }
                if ($type === ContainerWriter::TYPE_END) {
                    $decodedManifest = json_decode($payload, true, 128, JSON_THROW_ON_ERROR);
                    if (!is_array($decodedManifest)) {
                        throw new SecurityException('Final backup manifest validation failed.');
                    }
                    $finalManifest = $decodedManifest;
                    continue;
                }
                if ($type === ContainerWriter::TYPE_FILE_START) {
                    if (is_resource($currentHandle)) {
                        throw new SecurityException('Nested file record validation failed.');
                    }
                    $meta = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($meta)) { throw new SecurityException('File metadata validation failed.'); }
                    $logical = (string)($meta['path'] ?? '');
                    $currentExpectedSize = (int)($meta['size'] ?? -1);
                    if ($currentExpectedSize < 0) { throw new SecurityException('File size metadata validation failed.'); }
                    $free = @disk_free_space($stage);
                    if ($free !== false && $currentExpectedSize > max(0, $free - (64 * 1024 * 1024))) {
                        throw new StorageException('Insufficient staging space for restore file.');
                    }
                    $currentPath = $this->stagedPath($filesRoot, $logical);
                    $fileModes[$logical] = $this->safeRestoredMode((int)($meta['mode'] ?? 0644));
                    $this->ensureParentDirectory($currentPath, $filesRoot);
                    $currentHandle = @fopen($currentPath, 'x+b');
                    if (!is_resource($currentHandle)) { throw new StorageException('Unable to create staged restore file.'); }
                    @chmod($currentPath, 0600);
                    $currentActualSize = 0;
                    $currentHash = hash_init('sha256');
                    continue;
                }
                if ($type === ContainerWriter::TYPE_FILE_CHUNK) {
                    if (!is_resource($currentHandle) || $currentHash === null) {
                        throw new SecurityException('Orphan file chunk validation failed.');
                    }
                    $written = fwrite($currentHandle, $payload);
                    if ($written !== strlen($payload)) { throw new StorageException('Unable to write staged restore file.'); }
                    $currentActualSize += $written;
                    if ($currentActualSize > $currentExpectedSize) { throw new SecurityException('Restored file exceeded declared size.'); }
                    hash_update($currentHash, $payload);
                    continue;
                }
                if ($type === ContainerWriter::TYPE_FILE_END) {
                    if (!is_resource($currentHandle) || $currentHash === null) {
                        throw new SecurityException('Orphan file end record validation failed.');
                    }
                    $meta = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
                    $digest = hash_final($currentHash);
                    $expectedHash = is_array($meta) ? (string)($meta['sha256'] ?? '') : '';
                    $expectedSize = is_array($meta) ? (int)($meta['size'] ?? -1) : -1;
                    fclose($currentHandle);
                    $currentHandle = null;
                    $currentHash = null;
                    if ($currentActualSize !== $currentExpectedSize || $expectedSize !== $currentActualSize || !hash_equals($expectedHash, $digest)) {
                        throw new SecurityException('Staged file integrity validation failed.');
                    }
                    continue;
                }
                if ($type === ContainerWriter::TYPE_DB_TABLE) {
                    $meta = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
                    $table = is_array($meta) ? (string)($meta['table'] ?? '') : '';
                    $this->assertTable($table);
                    $rowsFile = $dbRoot . DIRECTORY_SEPARATOR . hash('sha256', $table) . '.rows';
                    $tables[$table] = ['schema' => (string)$meta['create_sql'], 'rows_file' => $rowsFile, 'large_rows' => []];
                    continue;
                }
                if ($type === ContainerWriter::TYPE_DB_ROWS) {
                    $meta = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
                    $table = is_array($meta) ? (string)($meta['table'] ?? '') : '';
                    $this->assertTable($table);
                    if (!isset($tables[$table])) { throw new SecurityException('Database rows preceded table schema.'); }
                    $line = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                    $free = @disk_free_space($stage);
                    if ($free !== false && strlen($line) > max(0, $free - (64 * 1024 * 1024))) {
                        throw new StorageException('Insufficient staging space for database restore data.');
                    }
                    $written = @file_put_contents($tables[$table]['rows_file'], $line, FILE_APPEND | LOCK_EX);
                    if ($written !== strlen($line)) { throw new StorageException('Unable to stage database rows.'); }
                    @chmod($tables[$table]['rows_file'], 0600);
                    continue;
                }
                if ($type === ContainerWriter::TYPE_DB_ROW_START) {
                    if ($largeRow !== null) { throw new SecurityException('Nested large database row validation failed.'); }
                    $meta = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
                    $table = is_array($meta) ? (string)($meta['table'] ?? '') : '';
                    $columns = is_array($meta['columns'] ?? null) ? $meta['columns'] : [];
                    $nulls = is_array($meta['nulls'] ?? null) ? $meta['nulls'] : [];
                    $this->assertTable($table);
                    if (!isset($tables[$table]) || $columns === [] || count($columns) > 2048) {
                        throw new SecurityException('Large database row metadata validation failed.');
                    }
                    $validatedColumns = [];
                    foreach ($columns as $column) {
                        if (!is_string($column) || preg_match('/^[A-Za-z0-9_$]+$/D', $column) !== 1 || isset($validatedColumns[$column])) {
                            throw new SecurityException('Large database row column validation failed.');
                        }
                        $validatedColumns[$column] = true;
                    }
                    $validatedNulls = [];
                    foreach ($nulls as $column) {
                        if (!is_string($column) || !isset($validatedColumns[$column])) {
                            throw new SecurityException('Large database row null-map validation failed.');
                        }
                        $validatedNulls[$column] = true;
                    }
                    $rowDir = $dbRoot . DIRECTORY_SEPARATOR . 'large-' . bin2hex(random_bytes(12));
                    $oldUmask = umask(0077);
                    try {
                        if (!mkdir($rowDir, 0700, false)) { throw new StorageException('Unable to create large-row staging directory.'); }
                    } finally {
                        umask($oldUmask);
                    }
                    $largeRow = [
                        'table' => $table,
                        'columns' => array_keys($validatedColumns),
                        'nulls' => $validatedNulls,
                        'row_dir' => $rowDir,
                        'cells' => [],
                    ];
                    continue;
                }
                if ($type === ContainerWriter::TYPE_DB_ROW_CELL) {
                    if (!is_array($largeRow) || strlen($payload) < 4) {
                        throw new SecurityException('Orphan large database row cell validation failed.');
                    }
                    $unpacked = unpack('Nlen', substr($payload, 0, 4));
                    $metaLength = (int)($unpacked['len'] ?? 0);
                    if ($metaLength < 2 || $metaLength > 65536 || 4 + $metaLength > strlen($payload)) {
                        throw new SecurityException('Large database row cell metadata length validation failed.');
                    }
                    $meta = json_decode(substr($payload, 4, $metaLength), true, 16, JSON_THROW_ON_ERROR);
                    $chunk = substr($payload, 4 + $metaLength);
                    $column = is_array($meta) ? (string)($meta['column'] ?? '') : '';
                    $offset = is_array($meta) ? (int)($meta['offset'] ?? -1) : -1;
                    $total = is_array($meta) ? (int)($meta['total'] ?? -1) : -1;
                    if ($column === '' || !in_array($column, $largeRow['columns'], true) || isset($largeRow['nulls'][$column]) || $offset < 0 || $total < 0 || $total > 2 * 1024 * 1024 * 1024) {
                        throw new SecurityException('Large database row cell validation failed.');
                    }
                    if (!isset($largeRow['cells'][$column])) {
                        if ($offset !== 0) { throw new SecurityException('Large database row cell offset validation failed.'); }
                        $cellPath = $largeRow['row_dir'] . DIRECTORY_SEPARATOR . hash('sha256', $column) . '.cell';
                        $largeRow['cells'][$column] = [
                            'path' => $cellPath,
                            'bytes' => 0,
                            'total' => $total,
                            'hash' => hash_init('sha256'),
                        ];
                    }
                    $cell = &$largeRow['cells'][$column];
                    if ($cell['total'] !== $total || $cell['bytes'] !== $offset || $cell['bytes'] + strlen($chunk) > $total) {
                        throw new SecurityException('Large database row cell sequence validation failed.');
                    }
                    $free = @disk_free_space($stage);
                    if ($free !== false && strlen($chunk) > max(0, $free - (64 * 1024 * 1024))) {
                        throw new StorageException('Insufficient staging space for large database row.');
                    }
                    $oldUmask = umask(0077);
                    try {
                        $mode = $cell['bytes'] === 0 ? 'x+b' : 'ab';
                        $cellHandle = @fopen($cell['path'], $mode);
                    } finally {
                        umask($oldUmask);
                    }
                    if (!is_resource($cellHandle)) { throw new StorageException('Unable to open large-row cell staging file.'); }
                    try {
                        if ($chunk !== '') {
                            $written = fwrite($cellHandle, $chunk);
                            if ($written !== strlen($chunk)) { throw new StorageException('Unable to stage large database row cell.'); }
                            $cell['bytes'] += $written;
                            hash_update($cell['hash'], $chunk);
                        }
                    } finally {
                        fclose($cellHandle);
                    }
                    @chmod($cell['path'], 0600);
                    unset($cell);
                    continue;
                }
                if ($type === ContainerWriter::TYPE_DB_ROW_END) {
                    if (!is_array($largeRow)) { throw new SecurityException('Orphan large database row end validation failed.'); }
                    $meta = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
                    $table = is_array($meta) ? (string)($meta['table'] ?? '') : '';
                    $cellsMeta = is_array($meta['cells'] ?? null) ? $meta['cells'] : [];
                    if (!hash_equals((string)$largeRow['table'], $table)) { throw new SecurityException('Large database row table validation failed.'); }
                    $values = [];
                    foreach ($largeRow['columns'] as $column) {
                        if (isset($largeRow['nulls'][$column])) {
                            $values[$column] = null;
                            continue;
                        }
                        if (!isset($largeRow['cells'][$column], $cellsMeta[$column]) || !is_array($cellsMeta[$column])) {
                            throw new SecurityException('Large database row completeness validation failed.');
                        }
                        $cell = $largeRow['cells'][$column];
                        $expectedSize = (int)($cellsMeta[$column]['size'] ?? -1);
                        $expectedHash = (string)($cellsMeta[$column]['sha256'] ?? '');
                        $actualHash = hash_final($cell['hash']);
                        if ($cell['bytes'] !== $cell['total'] || $cell['bytes'] !== $expectedSize || !hash_equals($expectedHash, $actualHash)) {
                            throw new SecurityException('Large database row integrity validation failed.');
                        }
                        $values[$column] = $cell['path'];
                    }
                    $tables[$table]['large_rows'][] = ['values' => $values];
                    $largeRow = null;
                    continue;
                }
                throw new SecurityException('Unknown backup record type.');
            }
            if (is_resource($currentHandle)) {
                throw new SecurityException('Backup ended with incomplete file record.');
            }
            if ($largeRow !== null) {
                throw new SecurityException('Backup ended with incomplete large database row.');
            }
            return ['files_root' => $filesRoot, 'file_modes' => $fileModes, 'tables' => $tables, 'header' => $reader->header(), 'manifest' => $finalManifest];
        } finally {
            if (is_resource($currentHandle)) { fclose($currentHandle); }
            $reader->close();
        }
    }

    private function applyFiles(string $filesRoot, string $targetRoot, ?string $restrictPrefix = null, array $fileModes = []): array
    {
        $sourceRoot = realpath($filesRoot);
        $destinationRoot = realpath($targetRoot);
        if ($sourceRoot === false || $destinationRoot === false) {
            throw new StorageException('Restore root resolution failed.');
        }
        $stats = ['files' => 0, 'bytes' => 0];
        foreach ($this->enumerateRelativeFiles($sourceRoot) as $relative) {
            $normalized = str_replace('\\', '/', $relative);
            if ($restrictPrefix !== null && $normalized !== $restrictPrefix && !str_starts_with($normalized, rtrim($restrictPrefix, '/') . '/')) {
                continue;
            }
            $source = realpath($sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized));
            if ($source === false || !is_file($source) || !str_starts_with($source, rtrim($sourceRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                throw new SecurityException('Staged source path escaped jail.');
            }
            $destination = $destinationRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
            if (!str_starts_with($destination, rtrim($destinationRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                throw new SecurityException('Restore destination path escaped jail.');
            }
            $this->ensureParentDirectory($destination, $destinationRoot, false);
            $temp = $destination . '.astraea-' . bin2hex(random_bytes(8)) . '.tmp';
            $in = @fopen($source, 'rb');
            $out = @fopen($temp, 'x+b');
            if (!is_resource($in) || !is_resource($out)) {
                if (is_resource($in)) fclose($in);
                if (is_resource($out)) fclose($out);
                throw new StorageException('Unable to create atomic restore file.');
            }
            $hashIn = hash_init('sha256');
            $hashOut = hash_init('sha256');
            $bytes = 0;
            try {
                while (!feof($in)) {
                    $chunk = fread($in, 1048576);
                    if ($chunk === false) { throw new StorageException('Unable to read staged restore file.'); }
                    if ($chunk === '') { continue; }
                    hash_update($hashIn, $chunk);
                    $written = fwrite($out, $chunk);
                    if ($written !== strlen($chunk)) { throw new StorageException('Unable to write restore destination file.'); }
                    hash_update($hashOut, $chunk);
                    $bytes += $written;
                }
                if (!fflush($out)) { throw new StorageException('Unable to flush restore destination file.'); }
            } finally {
                fclose($in); fclose($out);
            }
            if (!hash_equals(hash_final($hashIn), hash_final($hashOut))) {
                @unlink($temp);
                throw new SecurityException('Restore copy integrity validation failed.');
            }
            @chmod($temp, 0600);
            if (is_file($destination) && !@unlink($destination)) {
                @unlink($temp);
                throw new StorageException('Unable to replace existing destination file.');
            }
            if (!@rename($temp, $destination)) {
                @unlink($temp);
                throw new StorageException('Atomic restore commit failed.');
            }
            @chmod($destination, $fileModes[$normalized] ?? 0644);
            $stats['files']++;
            $stats['bytes'] += $bytes;
        }
        return $stats;
    }

    private function quarantinePluginTarget(string $target, string $plugin): string
    {
        $pluginRoot = realpath(WP_PLUGIN_DIR);
        if ($pluginRoot === false || !is_dir($pluginRoot)) {
            throw new StorageException('Plugin root resolution failed during rollback.');
        }
        $resolvedTarget = realpath($target);
        if ($resolvedTarget === false || (!is_dir($resolvedTarget) && !is_file($resolvedTarget))) {
            throw new StorageException('Current plugin target is unavailable for quarantine.');
        }
        if (!str_starts_with($resolvedTarget, rtrim($pluginRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Plugin quarantine source escaped jail.');
        }
        $quarantineRoot = $this->storage->quarantineDir();
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', basename($plugin)) ?: 'plugin';
        $destination = $quarantineRoot . DIRECTORY_SEPARATOR . $safe . '-' . bin2hex(random_bytes(10));
        if (!str_starts_with($destination, rtrim($quarantineRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Plugin quarantine destination escaped jail.');
        }
        if (!@rename($resolvedTarget, $destination)) {
            throw new StorageException('Unable to quarantine current plugin before rollback.');
        }
        return $destination;
    }

    private function rollbackQuarantine(string $target, string $quarantine): void
    {
        $pluginRoot = realpath(WP_PLUGIN_DIR);
        $quarantineRoot = realpath($this->storage->quarantineDir());
        if ($pluginRoot === false || $quarantineRoot === false) {
            throw new StorageException('Rollback quarantine root resolution failed.');
        }
        $resolvedQuarantine = realpath($quarantine);
        if ($resolvedQuarantine === false || !str_starts_with($resolvedQuarantine, rtrim($quarantineRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Rollback quarantine path escaped jail.');
        }
        $normalizedTarget = wp_normalize_path($target);
        $normalizedRoot = rtrim(wp_normalize_path($pluginRoot), '/') . '/';
        if (!str_starts_with($normalizedTarget, $normalizedRoot)) {
            throw new SecurityException('Rollback target path escaped jail.');
        }
        if (is_dir($target)) {
            $this->removeLiveTree($target, $pluginRoot);
        } elseif (is_file($target)) {
            @unlink($target);
        }
        if (!@rename($resolvedQuarantine, $target)) {
            throw new StorageException('Unable to restore quarantined plugin after rollback failure.');
        }
    }

    private function removeLiveTree(string $path, string $jailRoot): void
    {
        $root = realpath($jailRoot);
        $resolved = realpath($path);
        if ($root === false || $resolved === false || $resolved === $root || !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Live deletion path escaped jail.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $info) {
            $item = $info->getPathname();
            if ($info->isLink() || $info->isFile()) {
                if (!@unlink($item)) { throw new StorageException('Unable to remove failed rollback file.'); }
            } elseif ($info->isDir() && !@rmdir($item)) {
                throw new StorageException('Unable to remove failed rollback directory.');
            }
        }
        if (!@rmdir($resolved)) {
            throw new StorageException('Unable to remove failed rollback plugin root.');
        }
    }

    private function safeRestoredMode(int $mode): int
    {
        $mode &= 0777;
        $mode &= ~0022;
        $mode |= 0600;
        if (($mode & 0111) !== 0) {
            $mode |= 0100;
        }
        return $mode;
    }

    private function stagedPath(string $root, string $logical): string
    {
        $logical = str_replace('\\', '/', $logical);
        if ($logical === '' || str_starts_with($logical, '/') || str_contains($logical, "\0") || preg_match('#(^|/)\.\.(/|$)#', $logical) === 1 || preg_match('/^[A-Za-z]:\//', $logical) === 1) {
            throw new SecurityException('Restore path validation failed.');
        }
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false) { throw new StorageException('Staging root resolution failed.'); }
        $destination = $resolvedRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logical);
        if (!str_starts_with($destination, $resolvedRoot . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Path escaped jail.');
        }
        return $destination;
    }

    private function ensureParentDirectory(string $file, string $jailRoot, bool $private = true): void
    {
        $root = realpath($jailRoot);
        if ($root === false) { throw new StorageException('Directory jail resolution failed.'); }
        $parent = dirname($file);
        if (!str_starts_with($parent . DIRECTORY_SEPARATOR, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Directory path escaped jail.');
        }
        $oldUmask = umask(0077);
        try {
            if (!is_dir($parent) && !wp_mkdir_p($parent)) {
                throw new StorageException('Unable to create restore directory.');
            }
        } finally {
            umask($oldUmask);
        }
        $resolved = realpath($parent);
        if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new SecurityException('Created directory escaped jail.');
        }
        if ($private) { @chmod($resolved, 0700); }
    }

    private function enumerateRelativeFiles(string $root): array
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) { return []; }
        $result = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $info) {
            if ($info->isLink() || !$info->isFile()) { continue; }
            $path = realpath($info->getPathname());
            if ($path === false || !str_starts_with($path, rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                throw new SecurityException('Staged file path escaped jail.');
            }
            $result[] = ltrim(str_replace('\\', '/', substr($path, strlen($resolved))), '/');
        }
        sort($result, SORT_STRING);
        return $result;
    }

    private function assertTable(string $table): void
    {
        global $wpdb;
        if (!str_starts_with($table, $wpdb->prefix) || preg_match('/^[A-Za-z0-9_$]+$/D', $table) !== 1) {
            throw new SecurityException('Database table validation failed.');
        }
    }
}
