<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Update;

/**
 * Release package authenticity status.
 * Never displays synthetic "Verified" claims.
 *
 * @package Astraea\Update
 */
enum AuthenticityStatus: string {
    case SIGNED_VERIFIED = 'SIGNED_VERIFIED';
    case SIGNED_INVALID  = 'SIGNED_INVALID';
    case UNSIGNED        = 'UNSIGNED';
    case UNKNOWN         = 'UNKNOWN';

    public function isVerified(): bool {
        return $this === self::SIGNED_VERIFIED;
    }
}
