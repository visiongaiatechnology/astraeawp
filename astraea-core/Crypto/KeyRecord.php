<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Crypto;

use Astraea\Exceptions\SecurityException;

/**
 * Immutable representation of a cryptographic key record within the Astraea Keyring.
 *
 * @package Astraea\Crypto
 */
final class KeyRecord {

    private const HKDF_SALT = 'AstraeaOS-Core-KDF-Salt-v1';

    /**
     * @param string $keyId 8-hex-char unique fingerprint.
     * @param string $material 32-byte raw binary master key material.
     * @param KeyState $state Lifecycle state.
     * @param int $createdAt Unix timestamp.
     */
    public function __construct(
        public readonly string $keyId,
        #[\SensitiveParameter]
        public readonly string $material,
        public readonly KeyState $state,
        public readonly int $createdAt
    ) {
        if (strlen($this->material) !== 32) {
            throw new SecurityException('KeyRecord material must be exactly 32 bytes.');
        }
        if (strlen($this->keyId) < 8) {
            throw new SecurityException('KeyRecord identifier must be at least 8 characters.');
        }
    }

    /**
     * Derive a subkey for a specific domain context from this key record.
     *
     * @param KeyContext $context Domain context separation.
     * @param int $length Subkey length in bytes (default 32).
     * @return string Raw binary subkey.
     */
    public function deriveSubkey(KeyContext $context, int $length = 32): string {
        $info = $context->info();
        $subkey = hash_hkdf('sha256', $this->material, $length, $info, self::HKDF_SALT);
        if ($subkey === false) {
            throw new SecurityException('HKDF subkey derivation failure on key ' . $this->keyId);
        }
        return $subkey;
    }

    /**
     * Return a new record with updated state.
     */
    public function withState(KeyState $newState): self {
        return new self($this->keyId, $this->material, $newState, $this->createdAt);
    }
}
