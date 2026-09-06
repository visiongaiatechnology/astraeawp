<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Storage;

use Astraea\Vault\Config;
use Astraea\Vault\Exception\SecurityException;
use Astraea\Vault\Exception\StorageException;

final class LocalStorage
{
    private string $root;

    public function __construct()
    {
        $this->root = Config::storageRoot();
    }

    public function initialize(): void
    {
        $oldUmask = umask(0077);
        try {
            foreach ([$this->root, $this->root . '/backups', $this->root . '/staging', $this->root . '/quarantine'] as $dir) {
                if (!is_dir($dir)) {
                    $created = function_exists('wp_mkdir_p') ? wp_mkdir_p($dir) : @mkdir($dir, 0700, true);
                    if (!$created && !is_dir($dir)) {
                        throw new StorageException('Unable to create Astraea Vault storage directory: ' . $dir);
                    }
                }
                @chmod($dir, 0700);
            }
            $this->writeGuards();
        } finally {
            umask($oldUmask);
        }
    }

    public function root(): string
    {
        $this->initialize();
        $resolved = realpath($this->root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new StorageException('Storage root resolution failed.');
        }
        return $resolved;
    }

    public function backupsDir(): string
    {
        return $this->jailDirectory('backups');
    }

    public function stagingDir(): string
    {
        return $this->jailDirectory('staging');
    }

    public function quarantineDir(): string
    {
        return $this->jailDirectory('quarantine');
    }

    public function backupPath(string $id): string
    {
        $this->assertUuid($id);
        return $this->jailPath($this->backupsDir(), $id . '.avb');
    }

    public function partialPath(string $id): string
    {
        $this->assertUuid($id);
        return $this->jailPath($this->backupsDir(), $id . '.part');
    }

    public function serviceKeyPath(): string
    {
        return $this->jailPath($this->root(), '.service-key.vgt');
    }

    public function openExclusive(string $path)
    {
        $dir = realpath(dirname($path));
        if ($dir === false || !is_dir($dir)) {
            throw new StorageException('Destination directory resolution failed.');
        }
        $root = $this->root();
        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedPath = $dir . DIRECTORY_SEPARATOR . basename($path);
        if (!str_starts_with($normalizedPath, $normalizedRoot)) {
            throw new SecurityException('Path escaped jail.');
        }
        $oldUmask = umask(0077);
        try {
            $handle = @fopen($normalizedPath, 'x+b');
        } finally {
            umask($oldUmask);
        }
        if (!is_resource($handle)) {
            throw new StorageException('Unable to create backup file exclusively.');
        }
        @chmod($normalizedPath, 0600);
        return $handle;
    }

    public function resolveExistingBackup(string $id): string
    {
        $candidate = $this->backupPath($id);
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            throw new StorageException('Backup file not found.');
        }
        $dir = $this->backupsDir();
        if (!str_starts_with($resolved, rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Backup path escaped jail.');
        }
        return $resolved;
    }

    public function atomicCommit(string $partialPath, string $finalPath): void
    {
        $partial = realpath($partialPath);
        if ($partial === false || !is_file($partial)) {
            throw new StorageException('Partial backup is missing.');
        }
        $dir = $this->backupsDir();
        if (!str_starts_with($partial, rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Partial path escaped jail.');
        }
        $target = $this->jailPath($dir, basename($finalPath));
        if (!@rename($partial, $target)) {
            throw new StorageException('Atomic backup commit failed.');
        }
        @chmod($target, 0600);
    }

    public function deleteBackup(string $id): void
    {
        $path = $this->resolveExistingBackup($id);
        if (!@unlink($path)) {
            throw new StorageException('Unable to delete backup file.');
        }
    }

    public function createStaging(string $prefix): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '-', $prefix) ?: 'restore';
        $name = $safe . '-' . bin2hex(random_bytes(12));
        $base = $this->stagingDir();
        $path = $this->jailPath($base, $name);
        $oldUmask = umask(0077);
        try {
            if (!mkdir($path, 0700, false)) {
                throw new StorageException('Unable to create staging directory.');
            }
        } finally {
            umask($oldUmask);
        }
        $resolved = realpath($path);
        if ($resolved === false || !str_starts_with($resolved, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Staging path escaped jail.');
        }
        return $resolved;
    }

    public function removeTree(string $path): void
    {
        $resolved = realpath($path);
        $root = $this->root();
        if ($resolved === false || $resolved === $root || !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Deletion path escaped jail.');
        }
        $this->removeTreeUnsafe($resolved);
    }

    private function removeTreeUnsafe(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            throw new StorageException('Unable to enumerate directory for deletion.');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeTreeUnsafe($path . DIRECTORY_SEPARATOR . $item);
        }
        if (!@rmdir($path)) {
            throw new StorageException('Unable to remove directory.');
        }
    }

    private function jailDirectory(string $name): string
    {
        $root = $this->root();
        $candidate = $this->jailPath($root, $name);
        $resolved = realpath($candidate);
        if ($resolved === false || !is_dir($resolved)) {
            throw new StorageException('Storage subdirectory resolution failed.');
        }
        if (!str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Storage path escaped jail.');
        }
        return $resolved;
    }

    private function jailPath(string $input, string $filename): string
    {
        $resolvedDir = realpath($input);
        if ($resolvedDir === false || !is_dir($resolvedDir)) {
            throw new StorageException('Storage path resolution failed.');
        }
        if (basename($filename) !== $filename || str_contains($filename, "\0")) {
            throw new SecurityException('Path validation failed.');
        }
        $destination = $resolvedDir . DIRECTORY_SEPARATOR . $filename;
        if (!str_starts_with($destination, $resolvedDir . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Path escaped jail.');
        }
        return $destination;
    }

    private function assertUuid(string $id): void
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id) !== 1) {
            throw new SecurityException('Backup identifier validation failed.');
        }
    }

    private function writeGuards(): void
    {
        $guards = [
            '.htaccess' => "Require all denied\nDeny from all\nOptions -Indexes\n<FilesMatch \\\".*\\\">\nRequire all denied\n</FilesMatch>\n",
            'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization><directoryBrowse enabled="false" /></system.webServer></configuration>',
            'index.php' => "<?php http_response_code(404); exit;\n",
        ];
        foreach ($guards as $name => $contents) {
            $path = $this->root . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                @file_put_contents($path, $contents, LOCK_EX);
                @chmod($path, 0600);
            }
        }
    }
}
