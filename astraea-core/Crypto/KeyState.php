<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Crypto;

/**
 * Key lifecycle states for Astraea Cryptographic Keyring.
 *
 * @package Astraea\Crypto
 */
enum KeyState: string {
    /**
     * Active key used for new encryptions and historical decryptions.
     */
    case ACTIVE = 'ACTIVE';

    /**
     * Historical key allowed strictly for decrypting legacy data.
     * Never used for new encryptions.
     */
    case DECRYPT_ONLY = 'DECRYPT_ONLY';

    /**
     * Retired key archived and no longer permitted for automatic operations.
     */
    case RETIRED = 'RETIRED';

    /**
     * Compromised or invalidated key.
     * Decryption strictly blocked with security exception.
     */
    case REVOKED = 'REVOKED';
}
