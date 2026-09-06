<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Crypto;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;


/**
 * AstraeaOS Core Cryptographic Service.
 *
 * Implements:
 * - Modern AEAD encryption (XChaCha20-Poly1305 via libsodium with AES-256-GCM fallback).
 * - Additional Authenticated Data (AAD) binding to mitigate ciphertext transplantation attacks.
 * - Envelope serialization format: `vgt:a1:<algo>:<key_id>:<nonce_b64>:<ciphertext_b64>:<tag_b64>`.
 * - Fail-closed error handling (CryptoAuthenticationException on any MAC mismatch).
 * - Constant-time comparisons and CSPRNG nonces.
 * - VGT Diamant Standard exception hierarchy compliance.
 *
 * @package Astraea\Crypto
 */
final class CryptoService {

    public const FORMAT_VERSION = 'a1';
    public const ALGO_XCHACHA20_POLY1305 = 'xc20p';
    public const ALGO_AES_256_GCM         = 'a256g';

    /**
     * Encrypt plaintext using AEAD with context-derived key and optional AAD binding.
     *
     * @param string $plaintext Data to encrypt.
     * @param KeyContext $context Domain context for HKDF key derivation.
     * @param string $aad Additional Authenticated Data (e.g. table:col:id).
     * @return string Versioned envelope string.
     * @throws SecurityException If cryptographic primitive fails.
     */
    public static function encrypt(
        #[\SensitiveParameter]
        string $plaintext,
        KeyContext $context = KeyContext::SECRETS,
        string $aad = ''
    ): string {
        $activeKey = Keyring::getActiveKey();
        $key = $activeKey->deriveSubkey($context, 32);
        $keyId = $activeKey->keyId;

        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $aad,
                $nonce,
                $key
            );

            // In libsodium xchacha20poly1305_ietf, the 16-byte Poly1305 MAC tag is appended at the end of ciphertext
            $macLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
            $rawCipher = substr($ciphertext, 0, -$macLen);
            $tag = substr($ciphertext, -$macLen);

            return sprintf(
                'vgt:%s:%s:%s:%s:%s:%s',
                self::FORMAT_VERSION,
                self::ALGO_XCHACHA20_POLY1305,
                $keyId,
                rtrim(strtr(base64_encode($nonce), '+/', '-_'), '='),
                rtrim(strtr(base64_encode($rawCipher), '+/', '-_'), '='),
                rtrim(strtr(base64_encode($tag), '+/', '-_'), '=')
            );
        }

        // OpenSSL AES-256-GCM fallback
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new SecurityException('AEAD encryption failed in OpenSSL engine.');
        }

        return sprintf(
            'vgt:%s:%s:%s:%s:%s:%s',
            self::FORMAT_VERSION,
            self::ALGO_AES_256_GCM,
            $keyId,
            rtrim(strtr(base64_encode($iv), '+/', '-_'), '='),
            rtrim(strtr(base64_encode($ciphertext), '+/', '-_'), '='),
            rtrim(strtr(base64_encode($tag), '+/', '-_'), '=')
        );
    }

    /**
     * Decrypt an envelope string and verify its AEAD authentication tag against AAD.
     *
     * @param string $envelope Envelope string from encrypt().
     * @param KeyContext $context Domain context for HKDF key derivation.
     * @param string $aad Expected Additional Authenticated Data.
     * @return string Decrypted plaintext.
     * @throws ValidationException If format structure is invalid.
     * @throws CryptoAuthenticationException If decryption or tag verification fails.
     */
    public static function decrypt(
        string $envelope,
        KeyContext $context = KeyContext::SECRETS,
        string $aad = ''
    ): string {
        if (!str_starts_with($envelope, 'vgt:')) {
            throw new ValidationException('Invalid ciphertext envelope format.');
        }

        $parts = explode(':', $envelope);
        if (count($parts) !== 7) {
            throw new SecurityException('Corrupt or malformed ciphertext envelope structure.');
        }

        [$prefix, $version, $algo, $keyId, $nonceB64, $cipherB64, $tagB64] = $parts;

        if ($version !== self::FORMAT_VERSION) {
            throw new ValidationException(sprintf('Unsupported ciphertext envelope version "%s".', $version));
        }

        $nonce  = self::base64UrlDecode($nonceB64);
        $cipher = self::base64UrlDecode($cipherB64);
        $tag    = self::base64UrlDecode($tagB64);

        if ($nonce === false || $cipher === false || $tag === false) {
            throw new CryptoAuthenticationException('Failed to decode ciphertext envelope components.');
        }

        $keyRecord = Keyring::resolveKey($keyId);
        $key = $keyRecord->deriveSubkey($context, 32);

        if ($algo === self::ALGO_XCHACHA20_POLY1305) {
            if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
                throw new SecurityException('libsodium is required to decrypt XChaCha20-Poly1305 payload.');
            }

            if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES || strlen($tag) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
                throw new CryptoAuthenticationException('Invalid cryptographic parameter lengths.');
            }

            $rawPayload = $cipher . $tag;
            try {
                $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $rawPayload,
                    $aad,
                    $nonce,
                    $key
                );
            } catch (\SodiumException $e) {
                throw new CryptoAuthenticationException('Decryption failure in libsodium engine: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }

            if ($plaintext === false) {
                throw new CryptoAuthenticationException(
                    'Ciphertext authentication failed: data tampering, invalid key, or AAD mismatch.'
                );
            }

            return $plaintext;
        }

        if ($algo === self::ALGO_AES_256_GCM) {
            if (strlen($nonce) !== 12 || strlen($tag) !== 16) {
                throw new CryptoAuthenticationException('Invalid cryptographic parameter lengths.');
            }

            $plaintext = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $aad
            );

            if ($plaintext === false) {
                throw new CryptoAuthenticationException(
                    'AES-256-GCM authentication failed: data tampering, invalid key, or AAD mismatch.'
                );
            }

            return $plaintext;
        }

        throw new ValidationException(sprintf('Unsupported cryptographic algorithm "%s".', $algo));
    }

    /**
     * Check if a string is an Astraea encrypted envelope.
     */
    public static function isEncrypted(string $value): bool {
        return str_starts_with($value, 'vgt:' . self::FORMAT_VERSION . ':');
    }

    /**
     * URL-safe Base64 decode.
     */
    private static function base64UrlDecode(string $data): string|false {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
