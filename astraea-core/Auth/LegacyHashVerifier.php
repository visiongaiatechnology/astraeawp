<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Auth;

use PasswordHash;

/**
 * Legacy WordPress Password Hash Verifier.
 *
 * Provides backwards compatibility for:
 * - Argon2id ($argon2id$)
 * - WordPress 6.8+ / 7.x bcrypt ($wp$2y$...)
 * - Standard Bcrypt ($2y$, $2a$, $2b$)
 * - phpass ($P$, $S$)
 * - Pre-WordPress 2.5 MD5 (32 hex characters)
 *
 * Uses constant-time comparisons (hash_equals) for all verification checks.
 *
 * @package Astraea\Auth
 */
final class LegacyHashVerifier {

    /**
     * Identify whether a given hash is an obsolete legacy format.
     */
    public static function isLegacyHash(string $hash): bool {
        // If it starts with $argon2id$, it's a modern hash
        if (str_starts_with($hash, '$argon2id$')) {
            return false;
        }

        return true;
    }

    /**
     * Verify a plaintext password against a legacy hash format.
     *
     * @param string $password Plaintext password.
     * @param string $hash Stored database hash.
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

        // 1. Historic MD5 (<= 32 characters) using constant-time hash_equals
        if (strlen($hash) <= 32) {
            return hash_equals($hash, md5($password));
        }

        // 2. WordPress 6.8+ / 7.x $wp-prefixed Bcrypt
        if (str_starts_with($hash, '$wp')) {
            $bcryptHash = substr($hash, 3);
            $passwordToVerify = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
            if (password_verify($passwordToVerify, $bcryptHash)) {
                return true;
            }
            $trimmedToVerify = base64_encode(hash_hmac('sha384', trim($password), 'wp-sha384', true));
            return password_verify($trimmedToVerify, $bcryptHash);
        }

        // 3. WordPress Portable phpass ($P$ or $S$)
        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$S$')) {
            if (!class_exists('PasswordHash', false)) {
                $phpassPath = defined('ABSPATH') ? ABSPATH . 'wp-includes/class-phpass.php' : dirname(__DIR__, 2) . '/wp-includes/class-phpass.php';
                if (is_file($phpassPath)) {
                    require_once $phpassPath;
                }
            }

            if (class_exists('PasswordHash', false)) {
                $hasher = new PasswordHash(8, true);
                return $hasher->CheckPassword($password, $hash);
            }
        }

        // 4. Standard Bcrypt or Argon2i/Argon2id without custom wrapper
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$') || str_starts_with($hash, '$argon2i$')) {
            return password_verify($password, $hash);
        }

        // 5. Fallback native verification
        return password_verify($password, $hash);
    }
}
