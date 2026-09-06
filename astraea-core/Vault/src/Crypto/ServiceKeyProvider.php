<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Crypto;

use Astraea\Vault\Exception\StorageException;
use Astraea\Vault\Integration\AstraeaCore;
use Astraea\Vault\Storage\LocalStorage;

final class ServiceKeyProvider
{
    public function __construct(
        private readonly LocalStorage $storage,
        private readonly CryptoService $crypto
    ) {}

    public function key(): string
    {
        [$external, $source] = $this->externalSecret();
        if ($external !== null) {
            return $this->crypto->hkdf($external, 'astraea:vault:service:' . $source . ':v1');
        }

        $coreKey = AstraeaCore::deriveVaultServiceKey($this->crypto);
        if ($coreKey !== null) {
            return $coreKey;
        }

        $wpSecret = $this->wordpressSecret();
        if ($wpSecret !== null) {
            try {
                return $this->crypto->hkdf($wpSecret, 'astraea:vault:service:wp-config:v1');
            } finally {
                $this->crypto->wipe($wpSecret);
            }
        }

        $root = realpath($this->storage->root());
        $webRoot = realpath(ABSPATH);
        if ($root === false || $webRoot === false) {
            throw new StorageException('Service key storage root resolution failed.');
        }
        $normalizedRoot = rtrim(wp_normalize_path($root), '/') . '/';
        $normalizedWeb = rtrim(wp_normalize_path($webRoot), '/') . '/';
        if (str_starts_with($normalizedRoot, $normalizedWeb)) {
            throw new StorageException('A secure external service key is required because Vault storage is inside the public application root.');
        }

        $path = $this->storage->serviceKeyPath();
        if (!is_file($path)) {
            $this->createLocalKey($path);
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new StorageException('Service key file resolution failed.');
        }
        $raw = @file_get_contents($resolved);
        if (!is_string($raw) || strlen($raw) !== CryptoService::KEY_BYTES) {
            throw new StorageException('Service key file is invalid.');
        }
        try {
            return $this->crypto->hkdf($raw, 'astraea:vault:service:file:v1');
        } finally {
            $this->crypto->wipe($raw);
        }
    }

    public function sourceDescription(): string
    {
        [, $source] = $this->externalSecret();
        if ($source !== '') {
            return $source;
        }
        if (AstraeaCore::available()) {
            return 'astraea-core-master-key';
        }
        $wpSecret = $this->wordpressSecret();
        if ($wpSecret !== null) {
            $this->crypto->wipe($wpSecret);
            return 'wp-config-secrets';
        }
        return 'local-protected-file';
    }

    private function externalSecret(): array
    {
        if (defined('ASTRAEA_VAULT_SERVICE_KEY') && is_string(ASTRAEA_VAULT_SERVICE_KEY) && strlen(ASTRAEA_VAULT_SERVICE_KEY) >= 32) {
            return [ASTRAEA_VAULT_SERVICE_KEY, 'external-vault-key'];
        }
        $env = getenv('ASTRAEA_VAULT_SERVICE_KEY');
        if (is_string($env) && strlen($env) >= 32) {
            return [$env, 'external-vault-key'];
        }
        return [null, ''];
    }

    private function wordpressSecret(): ?string
    {
        $names = ['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'];
        $parts = [];
        foreach ($names as $name) {
            if (!defined($name)) {
                continue;
            }
            $value = constant($name);
            if (!is_string($value) || strlen($value) < 32 || stripos($value, 'put your unique phrase here') !== false) {
                continue;
            }
            $parts[] = $name . ':' . $value;
        }
        if (count($parts) < 4) {
            return null;
        }
        return implode("\n", $parts);
    }

    private function createLocalKey(string $path): void
    {
        $key = random_bytes(CryptoService::KEY_BYTES);
        $oldUmask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
            if (!is_resource($handle)) {
                if (is_file($path)) {
                    return;
                }
                throw new StorageException('Unable to create local service key.');
            }
            try {
                $written = fwrite($handle, $key);
                if ($written !== strlen($key) || !fflush($handle)) {
                    throw new StorageException('Unable to persist local service key.');
                }
            } finally {
                fclose($handle);
            }
            @chmod($path, 0600);
        } finally {
            umask($oldUmask);
            $this->crypto->wipe($key);
        }
    }
}
