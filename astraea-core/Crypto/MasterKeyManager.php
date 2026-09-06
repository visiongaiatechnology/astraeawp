<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Crypto;

use Astraea\Exceptions\SecurityException;

/**
 * AstraeaOS Master Key & Cryptographic Key Derivation Manager.
 *
 * Implements:
 * - Secure out-of-database master key acquisition.
 * - Deterministic HKDF (RFC 5869) key derivation with context separation.
 * - Subkey caching in memory to avoid repeated KDF overhead.
 * - Constant-time comparisons.
 * - VGT Diamant Standard exception hierarchy compliance.
 *
 * @package Astraea\Crypto
 */
final class MasterKeyManager {

    private const HKDF_SALT = 'AstraeaOS-Core-KDF-Salt-v1';
    private static ?string $masterKey = null;
    /** @var array<string, string> */
    private static array $derivedKeys = [];

    /**
     * Set the master key explicitly (e.g. from secure configuration or test setup).
     *
     * @param string $key Raw or hex-encoded 32+ byte key.
     */
    public static function setMasterKey(string $key): void {
        self::$masterKey = self::normalizeKey($key);
        self::$derivedKeys = []; // Clear subkey cache on master key change
    }

    /**
     * Retrieve the master key from secure environment or server configuration.
     *
     * Priority:
     * 1. In-memory master key (if explicitly set)
     * 2. Environment variable: `ASTRAEA_MASTER_KEY`
     * 3. Constant: `ASTRAEA_MASTER_KEY`
     * 4. External secret file: `ASTRAEA_MASTER_KEY_FILE`
     * 5. Fallback for WordPress compatibility: Derivation from `AUTH_KEY` and `SECURE_AUTH_KEY`.
     *
     * @return string 32-byte raw binary master key.
     * @throws SecurityException If master key material cannot be acquired.
     */
    public static function getMasterKey(): string {
        if (self::$masterKey !== null) {
            return self::$masterKey;
        }

        // 1. Environment variable
        $envKey = getenv('ASTRAEA_MASTER_KEY');
        if (is_string($envKey) && trim($envKey) !== '') {
            self::$masterKey = self::normalizeKey(trim($envKey));
            return self::$masterKey;
        }

        // 2. PHP Constant
        if (defined('ASTRAEA_MASTER_KEY') && is_string(ASTRAEA_MASTER_KEY) && trim(ASTRAEA_MASTER_KEY) !== '') {
            self::$masterKey = self::normalizeKey(trim(ASTRAEA_MASTER_KEY));
            return self::$masterKey;
        }

        // 3. External secure file
        $keyFile = getenv('ASTRAEA_MASTER_KEY_FILE');
        if (!is_string($keyFile) && defined('ASTRAEA_MASTER_KEY_FILE')) {
            $keyFile = ASTRAEA_MASTER_KEY_FILE;
        }
        if (is_string($keyFile) && is_file($keyFile) && is_readable($keyFile)) {
            $fileContent = file_get_contents($keyFile);
            if (is_string($fileContent) && trim($fileContent) !== '') {
                self::$masterKey = self::normalizeKey(trim($fileContent));
                return self::$masterKey;
            }
        }

        // 4. Fallback for WordPress core integration (e.g. during initial install before env is set)
        $invalidSalts = ['put your unique phrase here', 'Füge hier deine Zeichenkette ein'];
        if (defined('AUTH_KEY') && defined('SECURE_AUTH_KEY') &&
            !in_array(AUTH_KEY, $invalidSalts, true) &&
            !in_array(SECURE_AUTH_KEY, $invalidSalts, true)) {
            $material = hash('sha384', AUTH_KEY . ':' . SECURE_AUTH_KEY . ':AstraeaCoreFallbackV1', true);
            self::$masterKey = substr($material, 0, 32);
            return self::$masterKey;
        }

        throw new SecurityException(
            'AstraeaOS Master Key is not configured. Set ASTRAEA_MASTER_KEY environment variable or constant.'
        );
    }

    /**
     * Check if a valid master key is available.
     */
    public static function isConfigured(): bool {
        try {
            self::getMasterKey();
            return true;
        } catch (SecurityException) {
            return false;
        }
    }

    /**
     * Derive a 32-byte domain-isolated subkey using HKDF-SHA256.
     *
     * @param KeyContext $context The cryptographic context enum.
     * @param int $length Key length in bytes (default 32 for AEAD keys).
     * @return string Raw binary subkey.
     * @throws SecurityException If HKDF derivation fails.
     */
    public static function deriveSubkey(KeyContext $context, int $length = 32): string {
        $cacheKey = $context->value . ':' . $length;
        if (isset(self::$derivedKeys[$cacheKey])) {
            return self::$derivedKeys[$cacheKey];
        }

        $master = self::getMasterKey();
        $subkey = hash_hkdf(
            'sha256',
            $master,
            $length,
            $context->value,
            self::HKDF_SALT
        );

        if ($subkey === false || strlen($subkey) !== $length) {
            throw new SecurityException('Failed to derive cryptographic subkey via HKDF.');
        }

        self::$derivedKeys[$cacheKey] = $subkey;
        return $subkey;
    }

    /**
     * Get a short 8-hex-char identifier for the current master key (key fingerprint).
     * Used in envelope headers to detect key rotation.
     */
    public static function getKeyIdentifier(): string {
        return substr(hash('sha256', self::getMasterKey()), 0, 8);
    }

    /**
     * Normalize key input (hex, base64, or raw binary).
     */
    private static function normalizeKey(string $key): string {
        // Hex encoded 64 characters = 32 bytes
        if (strlen($key) === 64 && ctype_xdigit($key)) {
            $bin = hex2bin($key);
            if ($bin !== false) {
                return $bin;
            }
        }

        // Base64 encoded 44 characters = 32 bytes
        if (strlen($key) === 44 && str_ends_with($key, '=')) {
            $bin = base64_decode($key, true);
            if ($bin !== false && strlen($bin) === 32) {
                return $bin;
            }
        }

        // Raw 32 bytes
        if (strlen($key) === 32) {
            return $key;
        }

        // If arbitrary length string provided, derive a 32-byte key via SHA-256
        return hash('sha256', $key, true);
    }
}
