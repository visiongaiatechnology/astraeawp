<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Crypto;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\StorageException;

/**
 * Versioned cryptographic keyring for AstraeaOS WP.
 *
 * Historical key material is never stored in the database. Durable rotation
 * requires ASTRAEA_KEYRING_FILE to point to a protected file outside ABSPATH.
 */
final class Keyring {
    /** @var array<string, KeyRecord> */
    private static array $keys = [];
    private static ?string $activeKeyId = null;
    private static bool $initialized = false;

    public static function isInitialized(): bool {
        return self::$initialized;
    }

    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        self::$keys = [];
        self::$activeKeyId = null;
        self::$initialized = true;

        try {
            if (MasterKeyManager::isConfigured()) {
                self::registerKey(MasterKeyManager::getMasterKey(), KeyState::ACTIVE);
            }

            $historicalEnv = getenv('ASTRAEA_HISTORICAL_KEYS');
            if (is_string($historicalEnv) && trim($historicalEnv) !== '') {
                $candidates = preg_split('/[,;\s]+/', trim($historicalEnv));
                if (is_array($candidates)) {
                    foreach ($candidates as $candidate) {
                        if ($candidate !== '') {
                            self::registerKey($candidate, KeyState::DECRYPT_ONLY);
                        }
                    }
                }
            }

            $keyringFile = self::getConfiguredKeyringFile(false);
            if ($keyringFile !== null && is_file($keyringFile)) {
                if (is_link($keyringFile) || !is_readable($keyringFile)) {
                    throw new SecurityException('Configured cryptographic keyring file failed secure-read validation.');
                }
                $lines = file($keyringFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines === false) {
                    throw new StorageException('Unable to read configured cryptographic keyring file.');
                }
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    [$stateRaw, $material] = array_pad(explode(':', $line, 2), 2, '');
                    $state = KeyState::tryFrom(strtoupper(trim($stateRaw)));
                    if ($state === null || trim($material) === '') {
                        throw new SecurityException('Cryptographic keyring file contains an invalid record.');
                    }
                    self::registerKey(trim($material), $state);
                }
            }
        } catch (\Throwable $e) {
            self::$keys = [];
            self::$activeKeyId = null;
            self::$initialized = false;
            throw $e;
        }
    }

    public static function registerKey(string $rawKey, KeyState $state = KeyState::DECRYPT_ONLY): KeyRecord {
        $normalized = self::normalizeMaterial($rawKey);
        $keyId = substr(hash('sha256', $normalized), 0, 8);
        $record = new KeyRecord($keyId, $normalized, $state, time());

        $wasActive = self::$activeKeyId === $keyId;
        self::$keys[$keyId] = $record;
        self::$initialized = true;

        if ($state === KeyState::ACTIVE) {
            if (self::$activeKeyId !== null && self::$activeKeyId !== $keyId && isset(self::$keys[self::$activeKeyId])) {
                self::$keys[self::$activeKeyId] = self::$keys[self::$activeKeyId]->withState(KeyState::DECRYPT_ONLY);
            }
            self::$activeKeyId = $keyId;
            MasterKeyManager::setMasterKey($normalized);
        } elseif ($wasActive) {
            self::$activeKeyId = null;
        }

        return $record;
    }

    public static function getActiveKey(): KeyRecord {
        self::init();

        if (self::$activeKeyId === null || !isset(self::$keys[self::$activeKeyId])) {
            throw new SecurityException('No ACTIVE cryptographic key available in Astraea Keyring.');
        }

        $active = self::$keys[self::$activeKeyId];
        if ($active->state !== KeyState::ACTIVE) {
            throw new SecurityException('Active key record does not hold ACTIVE state.');
        }

        return $active;
    }

    public static function resolveKey(string $keyId): KeyRecord {
        self::init();

        if (!preg_match('/^[a-f0-9]{8}$/D', $keyId)) {
            throw new CryptoAuthenticationException('Decryption aborted: invalid key identifier format.');
        }
        if (!isset(self::$keys[$keyId])) {
            throw new CryptoAuthenticationException('Decryption aborted: referenced key identifier is not available.');
        }

        $record = self::$keys[$keyId];
        if ($record->state === KeyState::REVOKED) {
            throw new SecurityException('Decryption blocked because the referenced cryptographic key is revoked.');
        }
        if ($record->state === KeyState::RETIRED) {
            throw new SecurityException('Decryption blocked because the referenced cryptographic key is retired.');
        }

        return $record;
    }

    /**
     * Rotate the ACTIVE master key without making historical ciphertext
     * unreadable on the next request. The previous ACTIVE key is durably
     * persisted as DECRYPT_ONLY before the new key is committed.
     */
    public static function rotateKey(string $newKeyRaw): KeyRecord {
        self::init();
        $normalized = self::normalizeMaterial($newKeyRaw);
        $newId = substr(hash('sha256', $normalized), 0, 8);

        if (self::$activeKeyId === $newId && isset(self::$keys[$newId])) {
            return self::$keys[$newId];
        }

        if (self::$activeKeyId !== null && isset(self::$keys[self::$activeKeyId])) {
            $previous = self::$keys[self::$activeKeyId]->withState(KeyState::DECRYPT_ONLY);
            self::persistRecord($previous);
        }

        $active = self::registerKey($normalized, KeyState::ACTIVE);
        self::persistRecord($active);
        return $active;
    }

    /**
     * Persistently revoke a key. The revocation record is written before the
     * in-memory state changes, so a storage failure cannot pretend durability.
     */
    public static function revokeKey(string $keyId): void {
        self::init();
        if (!isset(self::$keys[$keyId])) {
            throw new SecurityException('Cannot revoke a cryptographic key identifier that is not present.');
        }

        $revoked = self::$keys[$keyId]->withState(KeyState::REVOKED);
        self::persistRecord($revoked);
        self::$keys[$keyId] = $revoked;
        if (self::$activeKeyId === $keyId) {
            self::$activeKeyId = null;
        }
    }

    public static function reset(): void {
        self::$keys = [];
        self::$activeKeyId = null;
        self::$initialized = false;
    }

    private static function persistRecord(KeyRecord $record): void {
        $path = self::getConfiguredKeyringFile(true);
        if ($path === null) {
            throw new StorageException('Durable key lifecycle change requires ASTRAEA_KEYRING_FILE outside the webroot.');
        }

        $directory = realpath(dirname($path));
        if ($directory === false || !is_dir($directory) || !is_writable($directory)) {
            throw new StorageException('Configured keyring directory is unavailable or not writable.');
        }

        $destination = $directory . DIRECTORY_SEPARATOR . basename($path);
        if (!str_starts_with($destination, $directory . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Cryptographic keyring path escaped its configured jail.');
        }
        if (is_link($destination)) {
            throw new SecurityException('Symbolic links are forbidden for cryptographic keyring storage.');
        }

        $records = self::readPersistentRecords($destination);
        $records[$record->keyId] = $record;
        ksort($records, SORT_STRING);

        $content = "# AstraeaOS cryptographic historical keyring v1\n";
        foreach ($records as $item) {
            $content .= $item->state->value . ':' . bin2hex($item->material) . "\n";
        }

        $oldUmask = umask(0077);
        try {
            $tmp = $destination . '.tmp-' . bin2hex(random_bytes(8));
            if (!str_starts_with($tmp, $directory . DIRECTORY_SEPARATOR)) {
                throw new SecurityException('Temporary keyring path escaped its configured jail.');
            }
            $written = file_put_contents($tmp, $content, LOCK_EX);
            if ($written === false || $written !== strlen($content)) {
                @unlink($tmp);
                throw new StorageException('Failed to persist complete cryptographic keyring state.');
            }
            if (!@chmod($tmp, 0600)) {
                @unlink($tmp);
                throw new StorageException('Failed to enforce 0600 keyring permissions.');
            }
            if (!@rename($tmp, $destination)) {
                @unlink($tmp);
                throw new StorageException('Failed to atomically commit cryptographic keyring state.');
            }
            @chmod($destination, 0600);
        } finally {
            umask($oldUmask);
            if (isset($content)) {
                $content = str_repeat("\0", strlen($content));
            }
        }
    }

    /** @return array<string, KeyRecord> */
    private static function readPersistentRecords(string $path): array {
        if (!is_file($path)) {
            return [];
        }
        if (is_link($path) || !is_readable($path)) {
            throw new SecurityException('Existing keyring file failed secure-read validation.');
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new StorageException('Failed to read existing cryptographic keyring records.');
        }

        $records = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$stateRaw, $material] = array_pad(explode(':', $line, 2), 2, '');
            $state = KeyState::tryFrom(strtoupper(trim($stateRaw)));
            if ($state === null || trim($material) === '') {
                throw new SecurityException('Existing keyring contains an invalid persistent record.');
            }
            $normalized = self::normalizeMaterial(trim($material));
            $id = substr(hash('sha256', $normalized), 0, 8);
            $records[$id] = new KeyRecord($id, $normalized, $state, time());
        }
        return $records;
    }

    private static function getConfiguredKeyringFile(bool $required): ?string {
        $configured = getenv('ASTRAEA_KEYRING_FILE');
        if ((!is_string($configured) || trim($configured) === '') && defined('ASTRAEA_KEYRING_FILE')) {
            $constant = constant('ASTRAEA_KEYRING_FILE');
            $configured = is_string($constant) ? $constant : '';
        }
        if (!is_string($configured) || trim($configured) === '') {
            if ($required) {
                return null;
            }
            return null;
        }

        $configured = trim($configured);
        $directory = realpath(dirname($configured));
        if ($directory === false || !is_dir($directory)) {
            if ($required) {
                throw new StorageException('Configured keyring parent directory does not exist.');
            }
            return null;
        }
        $destination = $directory . DIRECTORY_SEPARATOR . basename($configured);
        if (!str_starts_with($destination, $directory . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Configured cryptographic keyring path escaped its directory jail.');
        }

        if (defined('ABSPATH')) {
            $webroot = realpath((string) constant('ABSPATH'));
            if ($webroot !== false) {
                $prefix = rtrim($webroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                if ($destination === $webroot || str_starts_with($destination, $prefix)) {
                    throw new SecurityException('Cryptographic keyring must be stored outside the WordPress webroot.');
                }
            }
        }

        return $destination;
    }

    private static function normalizeMaterial(string $key): string {
        if (strlen($key) === 64 && ctype_xdigit($key)) {
            $bin = hex2bin($key);
            if ($bin !== false) {
                return $bin;
            }
        }
        if (strlen($key) === 44 && str_ends_with($key, '=')) {
            $bin = base64_decode($key, true);
            if ($bin !== false && strlen($bin) === 32) {
                return $bin;
            }
        }
        if (strlen($key) === 32) {
            return $key;
        }
        return hash('sha256', $key, true);
    }
}
