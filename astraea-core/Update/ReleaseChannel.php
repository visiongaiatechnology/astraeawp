<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Update;

/**
 * Supported release distribution channels for AstraeaOS WP.
 *
 * @package Astraea\Update
 */
enum ReleaseChannel: string {
    case STABLE      = 'stable';
    case BETA        = 'beta';
    case ALPHA       = 'alpha';
    case DEVELOPMENT = 'development';

    public function label(): string {
        return match ($this) {
            self::STABLE      => 'Stable (Production)',
            self::BETA        => 'Beta (Feature Complete)',
            self::ALPHA       => 'Alpha (Early Access)',
            self::DEVELOPMENT => 'Development (Bleeding Edge)',
        };
    }
}
