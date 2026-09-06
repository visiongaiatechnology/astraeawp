<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Auth;

use Astraea\Exceptions\SecurityException;

/**
 * Central AstraeaOS Password Hashing Service.
 *
 * Implements:
 * - Native PHP Argon2id password hashing.
 * - Enforced adaptive memory, time, and thread policies.
 * - Zero static salts; native CSPRNG individual salts.
 * - Integration with PepperManager for defense-in-depth pre-hashing.
 * - Seamless verification of legacy WordPress hashes (phpass, md5, $wp bcrypt).
 * - Identification of hashes requiring migration.
 * - VGT Diamant Standard exception hierarchy compliance.
 *
 * @package Astraea\Auth
 */
final class PasswordService {

    private static float $lastVerificationMs = 0.0;
    private static ?string $migrationHashFingerprint = null;

    /**
     * Hash a plaintext password using Argon2id and active policy options.
     *
     * @param string $password Plaintext password.
     * @return string Stored Argon2id hash string.
     * @throws SecurityException If password hashing fails.
     */
    public static function hash(
        #[\SensitiveParameter]
        string $password
    ): string {
        if (strlen($password) > 4096) {
            return '*';
        }

        $prepared = PepperManager::preparePassword($password);
        $options  = Argon2idPolicy::getOptions();

        $hash = password_hash($prepared, PASSWORD_ARGON2ID, $options);
        if ($hash === false) {
            throw new SecurityException('Failed to generate Argon2id password hash.');
        }

        return $hash;
    }

    /**
     * Check a plaintext password against a stored hash (modern Argon2id or legacy).
     *
     * @param string $password Plaintext password.
     * @param string $hash Stored hash string.
     * @return bool True if valid, false otherwise.
     */
    public static function verify(
        #[\SensitiveParameter]
        string $password,
        string $hash
    ): bool {
        if (strlen($password) > 4096 || $hash === '') {
            return false;
        }

        $start = microtime(true);

        // 1. Direct Argon2id hash verification with migration support for
        // an explicitly rotated server-side pepper.
        if (str_starts_with($hash, '$argon2id$')) {
            self::$migrationHashFingerprint = null;
            $prepared = PepperManager::preparePassword($password);
            $isValid = password_verify($prepared, $hash);

            if (!$isValid && PepperManager::isEnabled()) {
                foreach (PepperManager::getPreviousPeppers() as $previousPepper) {
                    if (password_verify(PepperManager::prepareWithPepper($password, $previousPepper), $hash)) {
                        $isValid = true;
                        self::$migrationHashFingerprint = hash('sha256', $hash);
                        break;
                    }
                }

                // Migration from hashes created before pepper activation.
                if (!$isValid && password_verify($password, $hash)) {
                    $isValid = true;
                    self::$migrationHashFingerprint = hash('sha256', $hash);
                }
            }

            self::$lastVerificationMs = (microtime(true) - $start) * 1000.0;
            return $isValid;
        }

        // 2. Legacy hash verification (phpass, md5, $wp bcrypt, standard bcrypt)
        $isValid = LegacyHashVerifier::verify($password, $hash);
        self::$lastVerificationMs = (microtime(true) - $start) * 1000.0;
        return $isValid;
    }

    /**
     * Determine if a stored hash needs to be rehashed to modern Argon2id.
     *
     * @param string $hash Stored hash string.
     * @return bool True if migration/rehash is needed.
     */
    public static function needsRehash(string $hash): bool {
        if ($hash === '' || $hash === '*') {
            return false;
        }

        $fingerprint = hash('sha256', $hash);
        if (self::$migrationHashFingerprint !== null && hash_equals(self::$migrationHashFingerprint, $fingerprint)) {
            return true;
        }

        // If it is any legacy format, it must be rehashed to Argon2id
        if (LegacyHashVerifier::isLegacyHash($hash)) {
            return true;
        }

        // If it is Argon2id, check if policy parameters have increased
        $options = Argon2idPolicy::getOptions();
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $options);
    }

    /**
     * Get the duration in milliseconds of the last verify operation.
     */
    public static function getLastVerificationMs(): float {
        return self::$lastVerificationMs;
    }
}
