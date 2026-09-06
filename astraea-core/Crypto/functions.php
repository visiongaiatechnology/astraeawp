<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

use Astraea\Crypto\CryptoService;
use Astraea\Crypto\KeyContext;
use Astraea\Crypto\SecureOptions;

if (!function_exists('astraea_encrypt_secret')) {
    /**
     * Encrypt an application secret using Astraea Crypto Service with AEAD.
     *
     * @param string $plaintext Secret value.
     * @param string $context Identifier for context isolation.
     * @param string $aad Additional authenticated data binding.
     * @return string Versioned envelope string.
     */
    function astraea_encrypt_secret(
        #[\SensitiveParameter]
        string $plaintext,
        string $context = 'secrets',
        string $aad = ''
    ): string {
        $keyContext = match ($context) {
            'database-options', 'options' => KeyContext::DATABASE_OPTIONS,
            'ged-defense', 'gedefense'    => KeyContext::GEDEFENSE,
            'security-events', 'events'   => KeyContext::SECURITY_EVENTS,
            'internal-tokens', 'tokens'   => KeyContext::INTERNAL_TOKENS,
            default                       => KeyContext::SECRETS,
        };

        return CryptoService::encrypt($plaintext, $keyContext, $aad);
    }
}

if (!function_exists('astraea_decrypt_secret')) {
    /**
     * Decrypt an application secret and verify its authenticity.
     *
     * @param string $envelope Versioned envelope string.
     * @param string $context Identifier for context isolation.
     * @param string $aad Additional authenticated data binding.
     * @return string Decrypted plaintext.
     */
    function astraea_decrypt_secret(
        string $envelope,
        string $context = 'secrets',
        string $aad = ''
    ): string {
        $keyContext = match ($context) {
            'database-options', 'options' => KeyContext::DATABASE_OPTIONS,
            'ged-defense', 'gedefense'    => KeyContext::GEDEFENSE,
            'security-events', 'events'   => KeyContext::SECURITY_EVENTS,
            'internal-tokens', 'tokens'   => KeyContext::INTERNAL_TOKENS,
            default                       => KeyContext::SECRETS,
        };

        return CryptoService::decrypt($envelope, $keyContext, $aad);
    }
}

if (!function_exists('astraea_secure_option_get')) {
    /**
     * Retrieve a decrypted secure option.
     */
    function astraea_secure_option_get(string $option, mixed $default = null): mixed {
        return SecureOptions::get($option, $default);
    }
}

if (!function_exists('astraea_secure_option_set')) {
    /**
     * Store an encrypted secure option.
     */
    function astraea_secure_option_set(string $option, mixed $value, bool $autoload = false): bool {
        return SecureOptions::set($option, $value, $autoload);
    }
}

if (!function_exists('astraea_secure_option_delete')) {
    /**
     * Delete a secure option.
     */
    function astraea_secure_option_delete(string $option): bool {
        return SecureOptions::delete($option);
    }
}
