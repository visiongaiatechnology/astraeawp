<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Backup;

use Astraea\Vault\Exception\SecurityException;
use Astraea\Vault\Exception\StorageException;
use Astraea\Vault\Storage\LocalStorage;

final class FileCollector
{
    private const CHUNK_BYTES = 1048576;

    public function __construct(private readonly LocalStorage $storage) {}

    public function writeTree(ContainerWriter $writer, string $basePath, string $logicalPrefix = '', array $extraExclusions = []): array
    {
        $base = realpath($basePath);
        if ($base === false || !is_dir($base)) {
            throw new StorageException('Backup source directory resolution failed.');
        }
        $exclusions = $this->defaultExclusions($base, $extraExclusions);
        $stats = ['files' => 0, 'bytes' => 0, 'skipped' => 0];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            $path = $info->getPathname();
            if ($info->isLink()) {
                $stats['skipped']++;
                continue;
            }
            $resolved = realpath($path);
            if ($resolved === false || !is_file($resolved)) {
                $stats['skipped']++;
                continue;
            }
            if (!str_starts_with($resolved, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                throw new SecurityException('Source path escaped jail.');
            }
            if ($this->isExcluded($resolved, $exclusions)) {
                $stats['skipped']++;
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($resolved, strlen($base))), '/');
            $logical = trim(str_replace('\\', '/', $logicalPrefix), '/');
            $logical = $logical === '' ? $relative : $logical . '/' . $relative;
            $this->assertLogicalPath($logical);
            $size = filesize($resolved);
            if ($size === false || $size < 0) {
                throw new StorageException('Unable to measure backup source file.');
            }
            $this->assertBackupCapacity($size);
            $writer->writeRecord(ContainerWriter::TYPE_FILE_START, json_encode([
                'path' => $logical,
                'size' => $size,
                'mtime' => $info->getMTime(),
                'mode' => $info->getPerms() & 0777,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $handle = @fopen($resolved, 'rb');
            if (!is_resource($handle)) {
                throw new StorageException('Unable to open backup source file.');
            }
            $beforeStat = fstat($handle);
            if (!is_array($beforeStat)) { fclose($handle); throw new StorageException('Unable to stat opened backup source file.'); }
            $hash = hash_init('sha256');
            $actual = 0;
            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, self::CHUNK_BYTES);
                    if ($chunk === false) {
                        throw new StorageException('Unable to read backup source file.');
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $actual += strlen($chunk);
                    hash_update($hash, $chunk);
                    $writer->writeRecord(ContainerWriter::TYPE_FILE_CHUNK, $chunk, true);
                }
                $afterStat = fstat($handle);
                if (!is_array($afterStat)) { throw new StorageException('Unable to re-stat backup source file.'); }
            } finally {
                fclose($handle);
            }
            if ($actual !== $size || (int)$beforeStat['size'] !== (int)$afterStat['size'] || (int)$beforeStat['mtime'] !== (int)$afterStat['mtime']) {
                throw new StorageException('Backup source file changed during read.');
            }
            $writer->writeRecord(ContainerWriter::TYPE_FILE_END, json_encode([
                'path' => $logical,
                'size' => $actual,
                'sha256' => hash_final($hash),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $stats['files']++;
            $stats['bytes'] += $actual;
        }
        return $stats;
    }

    public function writeSingleFile(ContainerWriter $writer, string $absolutePath, string $logicalPath): array
    {
        $resolved = realpath($absolutePath);
        if ($resolved === false || !is_file($resolved) || is_link($resolved)) {
            throw new StorageException('Backup source file resolution failed.');
        }
        $this->assertLogicalPath($logicalPath);
        $size = filesize($resolved);
        if ($size === false || $size < 0) {
            throw new StorageException('Unable to measure backup source file.');
        }
        $this->assertBackupCapacity($size);
        $writer->writeRecord(ContainerWriter::TYPE_FILE_START, json_encode([
            'path' => $logicalPath,
            'size' => $size,
            'mtime' => filemtime($resolved) ?: 0,
            'mode' => fileperms($resolved) !== false ? (fileperms($resolved) & 0777) : 0600,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $handle = @fopen($resolved, 'rb');
        if (!is_resource($handle)) {
            throw new StorageException('Unable to open backup source file.');
        }
        $beforeStat = fstat($handle);
        if (!is_array($beforeStat)) { fclose($handle); throw new StorageException('Unable to stat opened backup source file.'); }
        $hash = hash_init('sha256');
        $actual = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_BYTES);
                if ($chunk === false) { throw new StorageException('Unable to read backup source file.'); }
                if ($chunk === '') { continue; }
                $actual += strlen($chunk);
                hash_update($hash, $chunk);
                $writer->writeRecord(ContainerWriter::TYPE_FILE_CHUNK, $chunk, true);
            }
            $afterStat = fstat($handle);
            if (!is_array($afterStat)) { throw new StorageException('Unable to re-stat backup source file.'); }
        } finally {
            fclose($handle);
        }
        if ($actual !== $size || (int)$beforeStat['size'] !== (int)$afterStat['size'] || (int)$beforeStat['mtime'] !== (int)$afterStat['mtime']) {
            throw new StorageException('Backup source file changed during read.');
        }
        $writer->writeRecord(ContainerWriter::TYPE_FILE_END, json_encode([
            'path' => $logicalPath,
            'size' => $actual,
            'sha256' => hash_final($hash),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return ['files' => 1, 'bytes' => $actual, 'skipped' => 0];
    }

    private function assertBackupCapacity(int $incomingBytes): void
    {
        $free = @disk_free_space($this->storage->root());
        if ($free === false) { return; }
        $reserve = 128 * 1024 * 1024;
        if ($free <= $reserve || $incomingBytes > max(0, $free - $reserve)) {
            throw new StorageException('Insufficient free disk space to preserve the Vault safety reserve.');
        }
    }

    private function defaultExclusions(string $base, array $extra): array
    {
        $paths = [];
        foreach ([
            $this->storage->root(),
            WP_CONTENT_DIR . '/cache',
            WP_CONTENT_DIR . '/upgrade',
            WP_CONTENT_DIR . '/uploads/cache',
        ] as $candidate) {
            $real = realpath($candidate);
            if ($real !== false) { $paths[] = $real; }
        }
        foreach ($extra as $candidate) {
            if (!is_string($candidate)) { continue; }
            $real = realpath($candidate);
            if ($real !== false) { $paths[] = $real; }
        }
        return array_values(array_unique($paths));
    }

    private function isExcluded(string $path, array $exclusions): bool
    {
        foreach ($exclusions as $excluded) {
            if ($path === $excluded || str_starts_with($path, rtrim($excluded, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }
        return false;
    }

    private function assertLogicalPath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            throw new SecurityException('Logical path validation failed.');
        }
    }
}
