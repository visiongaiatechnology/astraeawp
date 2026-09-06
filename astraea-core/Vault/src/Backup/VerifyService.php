<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Backup;

use Astraea\Vault\Crypto\CryptoService;
use Astraea\Vault\Exception\StorageException;
use Astraea\Vault\Exception\SecurityException;
use Astraea\Vault\Storage\LocalStorage;

final class VerifyService
{
    public function __construct(
        private readonly LocalStorage $storage,
        private readonly CryptoService $crypto
    ) {}

    public function verify(string $backupId, string $masterKey): array
    {
        $path = $this->storage->resolveExistingBackup($backupId);
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new StorageException('Unable to open backup for verification.');
        }
        $reader = new ContainerReader($handle, $this->crypto, $masterKey);
        try {
            $headerId = (string)($reader->header()['backup_id'] ?? '');
            if (!hash_equals($backupId, $headerId)) {
                throw new SecurityException('Backup identifier binding validation failed.');
            }
            $manifest = $reader->consumeAndVerify();
            $hash = hash_file('sha256', $path);
            $size = filesize($path);
            if (!is_string($hash) || $size === false) {
                throw new StorageException('Unable to finalize backup verification metadata.');
            }
            return ['manifest' => $manifest, 'sha256' => $hash, 'size' => $size, 'header' => $reader->header()];
        } finally {
            $reader->close();
        }
    }
}
