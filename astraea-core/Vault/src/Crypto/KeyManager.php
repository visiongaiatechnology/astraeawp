<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Crypto;

use Astraea\Vault\Config;
use Astraea\Vault\Exception\CryptoException;
use Astraea\Vault\Exception\ValidationException;

final class KeyManager
{
    private const DEFAULT_OPSLIMIT = 4;
    private const DEFAULT_MEMLIMIT = 67108864;

    public function __construct(
        private readonly CryptoService $crypto,
        private readonly ServiceKeyProvider $serviceKeys
    ) {}

    public function initialized(): bool
    {
        $keyring = get_option(Config::OPTION_KEYRING, null);
        return is_array($keyring) && isset($keyring['password_slot'], $keyring['service_slot']);
    }

    public function initialize(string $passphrase): string
    {
        if ($this->initialized()) {
            throw new ValidationException('Astraea Vault is already initialized.');
        }
        $this->crypto->assertAvailable();
        $master = $this->crypto->randomKey();
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        [$opslimit, $memlimit] = $this->kdfParameters();
        $kek = $this->crypto->derivePasswordKey($passphrase, $salt, $opslimit, $memlimit);
        $service = $this->serviceKeys->key();
        $recoverySecret = random_bytes(32);
        $recoveryKey = $this->crypto->hkdf($recoverySecret, 'astraea:vault:recovery:v1');
        try {
            $keyring = [
                'version' => 1,
                'created_at' => gmdate('c'),
                'password_slot' => [
                    'kdf' => 'argon2id13',
                    'salt' => base64_encode($salt),
                    'opslimit' => $opslimit,
                    'memlimit' => $memlimit,
                    'wrapped' => $this->crypto->wrapKey($master, $kek, 'astraea:vault:master:password:v1'),
                ],
                'service_slot' => [
                    'source' => $this->serviceKeys->sourceDescription(),
                    'wrapped' => $this->crypto->wrapKey($master, $service, 'astraea:vault:master:service:v1'),
                ],
                'recovery_slot' => [
                    'wrapped' => $this->crypto->wrapKey($master, $recoveryKey, 'astraea:vault:master:recovery:v1'),
                ],
            ];
            if (!update_option(Config::OPTION_KEYRING, $keyring, false)) {
                $stored = get_option(Config::OPTION_KEYRING, null);
                if ($stored !== $keyring) {
                    throw new CryptoException('Unable to persist Vault keyring.');
                }
            }
        } finally {
            $this->crypto->wipe($master);
            $this->crypto->wipe($kek);
            $this->crypto->wipe($service);
            $this->crypto->wipe($recoveryKey);
        }
        return rtrim(strtr(base64_encode($recoverySecret), '+/', '-_'), '=');
    }

    public function unlockWithPassphrase(string $passphrase): string
    {
        $keyring = $this->keyring();
        $slot = $keyring['password_slot'];
        $salt = base64_decode((string)($slot['salt'] ?? ''), true);
        if ($salt === false) {
            throw new CryptoException('Password key slot salt decoding failed.');
        }
        $opslimit = (int)($slot['opslimit'] ?? 0);
        $memlimit = (int)($slot['memlimit'] ?? 0);
        if ($opslimit < 2 || $opslimit > 10 || $memlimit < 32 * 1024 * 1024 || $memlimit > 1024 * 1024 * 1024) {
            throw new CryptoException('Password KDF parameter validation failed.');
        }
        $kek = $this->crypto->derivePasswordKey($passphrase, $salt, $opslimit, $memlimit);
        try {
            return $this->crypto->unwrapKey((array)$slot['wrapped'], $kek, 'astraea:vault:master:password:v1');
        } finally {
            $this->crypto->wipe($kek);
        }
    }

    public function unlockWithServiceKey(): string
    {
        $keyring = $this->keyring();
        $service = $this->serviceKeys->key();
        try {
            return $this->crypto->unwrapKey((array)$keyring['service_slot']['wrapped'], $service, 'astraea:vault:master:service:v1');
        } finally {
            $this->crypto->wipe($service);
        }
    }

    public function unlockWithRecoveryKey(string $recoveryKey): string
    {
        $normalized = strtr(trim($recoveryKey), '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding !== 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }
        $secret = base64_decode($normalized, true);
        if ($secret === false || strlen($secret) !== 32) {
            throw new ValidationException('Recovery key is invalid.');
        }
        $keyring = $this->keyring();
        $key = $this->crypto->hkdf($secret, 'astraea:vault:recovery:v1');
        try {
            return $this->crypto->unwrapKey((array)$keyring['recovery_slot']['wrapped'], $key, 'astraea:vault:master:recovery:v1');
        } finally {
            $this->crypto->wipe($secret);
            $this->crypto->wipe($key);
        }
    }

    public function portableSlots(): array
    {
        $keyring = $this->keyring();
        return [
            'password' => $keyring['password_slot'],
            'recovery' => $keyring['recovery_slot'] ?? null,
        ];
    }

    public function unlockPortableWithPassphrase(array $header, string $passphrase): string
    {
        $slot = $header['portable_key_slots']['password'] ?? null;
        if (!is_array($slot)) {
            throw new CryptoException('Portable password key slot is unavailable.');
        }
        $salt = base64_decode((string)($slot['salt'] ?? ''), true);
        if ($salt === false) {
            throw new CryptoException('Portable password key slot salt decoding failed.');
        }
        $opslimit = (int)($slot['opslimit'] ?? 0);
        $memlimit = (int)($slot['memlimit'] ?? 0);
        if ($opslimit < 2 || $opslimit > 10 || $memlimit < 32 * 1024 * 1024 || $memlimit > 1024 * 1024 * 1024) {
            throw new CryptoException('Portable password KDF parameter validation failed.');
        }
        $kek = $this->crypto->derivePasswordKey($passphrase, $salt, $opslimit, $memlimit);
        try {
            return $this->crypto->unwrapKey((array)($slot['wrapped'] ?? []), $kek, 'astraea:vault:master:password:v1');
        } finally {
            $this->crypto->wipe($kek);
        }
    }

    public function changePassphrase(string $oldPassphrase, string $newPassphrase): void
    {
        $master = $this->unlockWithPassphrase($oldPassphrase);
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        [$opslimit, $memlimit] = $this->kdfParameters();
        $kek = $this->crypto->derivePasswordKey($newPassphrase, $salt, $opslimit, $memlimit);
        try {
            $keyring = $this->keyring();
            $keyring['password_slot'] = [
                'kdf' => 'argon2id13',
                'salt' => base64_encode($salt),
                'opslimit' => $opslimit,
                'memlimit' => $memlimit,
                'wrapped' => $this->crypto->wrapKey($master, $kek, 'astraea:vault:master:password:v1'),
            ];
            $keyring['password_changed_at'] = gmdate('c');
            if (!update_option(Config::OPTION_KEYRING, $keyring, false)) {
                $stored = get_option(Config::OPTION_KEYRING, null);
                if ($stored !== $keyring) {
                    throw new CryptoException('Unable to persist rotated Vault password key slot.');
                }
            }
        } finally {
            $this->crypto->wipe($master);
            $this->crypto->wipe($kek);
        }
    }

    /** @return array{0:int,1:int} */
    private function kdfParameters(): array
    {
        $opslimit = self::DEFAULT_OPSLIMIT;
        $memlimit = self::DEFAULT_MEMLIMIT;

        if (class_exists('\\Astraea\\Auth\\Argon2idPolicy')) {
            try {
                /** @var class-string $policy */
                $policy = '\\Astraea\\Auth\\Argon2idPolicy';
                $options = $policy::getOptions();
                if (is_array($options)) {
                    $timeCost = (int)($options['time_cost'] ?? $opslimit);
                    $memoryKiB = (int)($options['memory_cost'] ?? intdiv($memlimit, 1024));
                    $opslimit = max(2, min(10, $timeCost));
                    $memlimit = max(32 * 1024 * 1024, min(256 * 1024 * 1024, $memoryKiB * 1024));
                }
            } catch (\Throwable) {
                // Safe defaults remain active.
            }
        }

        return [$opslimit, $memlimit];
    }

    private function keyring(): array
    {
        $keyring = get_option(Config::OPTION_KEYRING, null);
        if (!is_array($keyring) || (int)($keyring['version'] ?? 0) !== 1) {
            throw new CryptoException('Vault keyring is not initialized or is invalid.');
        }
        return $keyring;
    }
}
