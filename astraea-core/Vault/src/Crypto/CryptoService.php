<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Crypto;

use Astraea\Vault\Exception\CryptoException;
use Astraea\Vault\Exception\ValidationException;

final class CryptoService
{
    public const CIPHER = 'aes-256-gcm';
    public const KEY_BYTES = 32;
    public const NONCE_BYTES = 12;
    public const TAG_BYTES = 16;

    public function assertAvailable(): void
    {
        if (!extension_loaded('openssl') || !extension_loaded('sodium')) {
            throw new CryptoException('Required cryptographic extension is unavailable.');
        }
        if (!in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new CryptoException('AES-256-GCM is unavailable.');
        }
    }

    public function randomKey(): string
    {
        return random_bytes(self::KEY_BYTES);
    }

    public function encrypt(string $plaintext, string $key, string $aad = ''): array
    {
        $this->assertKey($key);
        $nonce = random_bytes(self::NONCE_BYTES);
        return $this->encryptWithNonce($plaintext, $key, $nonce, $aad);
    }

    public function encryptWithNonce(string $plaintext, string $key, string $nonce, string $aad = ''): array
    {
        $this->assertKey($key);
        if (strlen($nonce) !== self::NONCE_BYTES) {
            throw new ValidationException('Invalid cryptographic nonce length.');
        }
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            self::TAG_BYTES
        );
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new CryptoException('Authenticated encryption failed.');
        }
        return ['nonce' => $nonce, 'tag' => $tag, 'ciphertext' => $ciphertext];
    }

    public function decrypt(string $ciphertext, string $key, string $nonce, string $tag, string $aad = ''): string
    {
        $this->assertKey($key);
        if (strlen($nonce) !== self::NONCE_BYTES || strlen($tag) !== self::TAG_BYTES) {
            throw new CryptoException('Ciphertext metadata validation failed.');
        }
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad
        );
        if ($plaintext === false) {
            throw new CryptoException('Authenticated decryption failed.');
        }
        return $plaintext;
    }

    public function wrapKey(string $keyToWrap, string $wrappingKey, string $aad): array
    {
        if (strlen($keyToWrap) !== self::KEY_BYTES) {
            throw new ValidationException('Invalid wrapped key length.');
        }
        $box = $this->encrypt($keyToWrap, $wrappingKey, $aad);
        return [
            'nonce' => base64_encode($box['nonce']),
            'tag' => base64_encode($box['tag']),
            'ciphertext' => base64_encode($box['ciphertext']),
        ];
    }

    public function unwrapKey(array $box, string $wrappingKey, string $aad): string
    {
        foreach (['nonce', 'tag', 'ciphertext'] as $field) {
            if (!isset($box[$field]) || !is_string($box[$field])) {
                throw new CryptoException('Wrapped key structure validation failed.');
            }
        }
        $nonce = base64_decode($box['nonce'], true);
        $tag = base64_decode($box['tag'], true);
        $ciphertext = base64_decode($box['ciphertext'], true);
        if ($nonce === false || $tag === false || $ciphertext === false) {
            throw new CryptoException('Wrapped key decoding failed.');
        }
        $key = $this->decrypt($ciphertext, $wrappingKey, $nonce, $tag, $aad);
        $this->assertKey($key);
        return $key;
    }

    public function derivePasswordKey(string $passphrase, string $salt, int $opslimit, int $memlimit): string
    {
        if (strlen($passphrase) < 16 || strlen($passphrase) > 4096) {
            throw new ValidationException('Vault passphrase must contain between 16 and 4096 bytes.');
        }
        if (strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            throw new CryptoException('Argon2id salt validation failed.');
        }
        try {
            return sodium_crypto_pwhash(
                self::KEY_BYTES,
                $passphrase,
                $salt,
                $opslimit,
                $memlimit,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
            );
        } catch (\SodiumException $e) {
            throw new CryptoException('Argon2id key derivation failed.', 0, $e);
        }
    }

    public function hkdf(string $material, string $context): string
    {
        if ($material === '') {
            throw new CryptoException('Key material is empty.');
        }
        $derived = hash_hkdf('sha256', $material, self::KEY_BYTES, $context, 'astraea-vault-v1');
        if (strlen($derived) !== self::KEY_BYTES) {
            throw new CryptoException('HKDF derivation failed.');
        }
        return $derived;
    }

    public function wipe(string &$secret): void
    {
        if ($secret !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($secret);
        }
        $secret = '';
    }

    private function assertKey(string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new CryptoException('Cryptographic key length validation failed.');
        }
    }
}
