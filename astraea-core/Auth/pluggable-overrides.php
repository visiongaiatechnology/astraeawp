<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

use Astraea\Auth\PasswordService;

if (!function_exists('wp_hash_password')) {
    /**
     * AstraeaOS native Argon2id implementation of wp_hash_password().
     *
     * @param string $password Plaintext password to hash.
     * @return string Stored Argon2id hash.
     */
    function wp_hash_password(
        #[\SensitiveParameter]
        $password
    ): string {
        return PasswordService::hash((string) $password);
    }
}

if (!function_exists('wp_check_password')) {
    /**
     * AstraeaOS native verification of wp_check_password().
     *
     * Verifies against modern Argon2id or legacy hashes (phpass, MD5, $wp bcrypt).
     *
     * @param string $password Plaintext password.
     * @param string $hash Stored hash to verify against.
     * @param string|int $user_id Optional user ID.
     * @return bool True if valid, false otherwise.
     */
    function wp_check_password(
        #[\SensitiveParameter]
        $password,
        $hash,
        $user_id = ''
    ): bool {
        $check = PasswordService::verify((string) $password, (string) $hash);

        /**
         * Standard WordPress filter check_password compatibility.
         */
        if (function_exists('apply_filters')) {
            return (bool) apply_filters('check_password', $check, $password, $hash, $user_id);
        }

        return $check;
    }
}

if (!function_exists('wp_password_needs_rehash')) {
    /**
     * AstraeaOS native implementation of wp_password_needs_rehash().
     *
     * @param string $hash Stored hash.
     * @return bool True if rehash to modern Argon2id is needed.
     */
    function wp_password_needs_rehash(string $hash): bool {
        return PasswordService::needsRehash($hash);
    }
}
