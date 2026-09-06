<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Crypto;

use JsonException;

/**
 * AstraeaOS Secure Options API.
 *
 * Provides authenticated application-level encryption for sensitive options
 * (API keys, tokens, credentials, security parameters) using the central CryptoService.
 *
 * Each option is bound via AAD to its option name to prevent transplanting
 * valid ciphertexts across different option keys.
 *
 * @package Astraea\Crypto
 */
final class SecureOptions {

    private const AAD_PREFIX = 'astraea:sec_opt:';

    /**
     * Get and decrypt a secure option.
     *
     * @param string $option Option name.
     * @param mixed $default Default value if option does not exist or decryption fails.
     * @return mixed Decrypted value (deserialized from JSON) or default.
     */
    public static function get(string $option, mixed $default = null): mixed {
        $raw = get_option($option, null);
        if ($raw === null || !is_string($raw) || $raw === '') {
            return $default;
        }

        if (!CryptoService::isEncrypted($raw)) {
            // Unencrypted legacy option value
            return $raw;
        }

        try {
            $aad = self::AAD_PREFIX . $option;
            $plaintext = CryptoService::decrypt($raw, KeyContext::DATABASE_OPTIONS, $aad);
            return json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            error_log(sprintf('[AstraeaOS SecureOptions] Decryption failed for option "%s": %s', $option, $e->getMessage()));
            return $default;
        }
    }

    /**
     * Encrypt and store a secure option.
     *
     * @param string $option Option name.
     * @param mixed $value Value to encrypt and save.
     * @param bool $autoload Whether to autoload this option on page load.
     * @return bool True on success, false on failure.
     */
    public static function set(string $option, mixed $value, bool $autoload = false): bool {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $aad = self::AAD_PREFIX . $option;
            $ciphertext = CryptoService::encrypt($json, KeyContext::DATABASE_OPTIONS, $aad);

            $autoloadStr = $autoload ? 'yes' : 'no';
            return update_option($option, $ciphertext, $autoloadStr);
        } catch (\Throwable $e) {
            error_log(sprintf('[AstraeaOS SecureOptions] Encryption failed for option "%s": %s', $option, $e->getMessage()));
            return false;
        }
    }

    /**
     * Delete a secure option.
     *
     * @param string $option Option name.
     * @return bool True on success, false on failure.
     */
    public static function delete(string $option): bool {
        return delete_option($option);
    }
}
