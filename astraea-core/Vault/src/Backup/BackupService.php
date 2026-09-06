<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Backup;

use Astraea\Vault\Crypto\CryptoService;
use Astraea\Vault\Crypto\KeyManager;
use Astraea\Vault\Exception\StorageException;
use Astraea\Vault\Exception\ValidationException;
use Astraea\Vault\Repository\BackupRepository;
use Astraea\Vault\Storage\LocalStorage;
use Astraea\Vault\Support\DbLock;
use Astraea\Vault\Support\Uuid;

final class BackupService
{
    public function __construct(
        private readonly LocalStorage $storage,
        private readonly CryptoService $crypto,
        private readonly KeyManager $keys,
        private readonly BackupRepository $repository,
        private readonly FileCollector $files,
        private readonly DatabaseExporter $database,
        private readonly VerifyService $verifier
    ) {}

    public function create(string $type, string $masterKey, string $trigger = 'manual', array $context = []): array
    {
        if (!in_array($type, ['full', 'database', 'plugin'], true)) {
            throw new ValidationException('Unsupported backup type.');
        }
        $id = Uuid::v4();
        $partial = $this->storage->partialPath($id);
        $final = $this->storage->backupPath($id);
        $this->repository->create([
            'id' => $id,
            'type' => $type,
            'status' => 'running',
            'filename' => basename($final),
            'trigger_source' => $trigger,
            'meta' => $this->safeContext($context),
        ]);

        $lock = new DbLock();
        $writer = null;
        try {
            $lock->acquire('astraea_vault_backup', 0);
            $this->ensureDiskSpace();
            $handle = $this->storage->openExclusive($partial);
            $writer = new ContainerWriter($handle, $this->crypto, $id, $masterKey, $type, [
                'astraea_vault_version' => ASTRAEA_VAULT_VERSION,
                'trigger' => $trigger,
            ], $this->keys->portableSlots());
            $manifest = [
                'backup_id' => $id,
                'type' => $type,
                'files' => ['files' => 0, 'bytes' => 0, 'skipped' => 0],
                'database' => ['tables' => 0, 'rows' => 0],
                'context' => $this->safeContext($context),
            ];
            if ($type === 'full' || $type === 'database') {
                $manifest['database'] = $this->database->writeAll($writer);
            }
            if ($type === 'full') {
                $manifest['files'] = $this->files->writeTree($writer, ABSPATH, '');
            }
            if ($type === 'plugin') {
                $plugin = (string)($context['plugin'] ?? '');
                $plugin = wp_normalize_path($plugin);
                if (dirname($plugin) === '.') {
                    $root = realpath(WP_PLUGIN_DIR);
                    $file = realpath(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . basename($plugin));
                    if ($root === false || $file === false || !is_file($file) || !str_starts_with($file, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                        throw new StorageException('Single-file plugin snapshot source is unavailable.');
                    }
                    $manifest['files'] = $this->files->writeSingleFile($writer, $file, basename($plugin));
                } else {
                    $pluginDir = $this->pluginDirectory($plugin);
                    $slug = basename($pluginDir);
                    $manifest['files'] = $this->files->writeTree($writer, $pluginDir, $slug);
                }
            }
            $writer->finalize($manifest);
            $writer->close();
            $writer = null;
            $this->storage->atomicCommit($partial, $final);
            $verification = $this->verifier->verify($id, $masterKey);
            $this->repository->updateStatus($id, 'verified', (int)$verification['size'], (string)$verification['sha256'], $manifest);
            return array_merge(['id' => $id, 'status' => 'verified'], $verification);
        } catch (\Throwable $e) {
            if ($writer instanceof ContainerWriter) {
                $writer->close();
            }
            if (is_file($partial)) { @unlink($partial); }
            try { $this->repository->updateStatus($id, 'failed', 0, '', ['error' => get_class($e)]); } catch (\Throwable) {}
            throw $e;
        } finally {
            $lock->release('astraea_vault_backup');
        }
    }

    private function pluginDirectory(string $plugin): string
    {
        if ($plugin === '' || str_contains($plugin, "\0") || str_contains($plugin, '..')) {
            throw new ValidationException('Plugin identifier is invalid.');
        }
        $plugin = wp_normalize_path($plugin);
        $dirName = dirname($plugin);
        $candidate = $dirName === '.' ? WP_PLUGIN_DIR : WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $dirName;
        $resolvedRoot = realpath(WP_PLUGIN_DIR);
        $resolved = realpath($candidate);
        if ($resolvedRoot === false || $resolved === false || !is_dir($resolved)) {
            throw new StorageException('Plugin directory is unavailable for snapshot.');
        }
        if ($resolved !== $resolvedRoot && !str_starts_with($resolved, rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new \Astraea\Vault\Exception\SecurityException('Plugin path escaped jail.');
        }
        return $resolved;
    }

    private function ensureDiskSpace(): void
    {
        $free = @disk_free_space($this->storage->root());
        if ($free !== false && $free < 128 * 1024 * 1024) {
            throw new StorageException('Insufficient free disk space for protected backup operation.');
        }
    }

    private function safeContext(array $context): array
    {
        $safe = [];
        foreach (['plugin', 'previous_version', 'new_version', 'transaction_id'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) {
                $safe[$key] = substr((string)$context[$key], 0, 255);
            }
        }
        return $safe;
    }
}
