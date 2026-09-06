<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Diagnostics;

/**
 * Standardized Health & Integrity States for AstraeaOS WP.
 *
 * Replaces fake "OPTIMAL" states with mathematically verifiable probes.
 *
 * @package Astraea\Diagnostics
 */
enum HealthStatus: string {
    case HEALTHY  = 'HEALTHY';
    case DEGRADED = 'DEGRADED';
    case WARNING  = 'WARNING';
    case CRITICAL = 'CRITICAL';
    case DISABLED = 'DISABLED';
    case UNKNOWN  = 'UNKNOWN';

    /**
     * Get CSS badge class.
     */
    public function badgeClass(): string {
        return match ($this) {
            self::HEALTHY  => 'secure success',
            self::DEGRADED => 'warning',
            self::WARNING  => 'warning',
            self::CRITICAL => 'danger alert',
            self::DISABLED => 'default',
            self::UNKNOWN  => 'default',
        };
    }

    /**
     * Get CSS class for UI badges and indicators.
     */
    public function cssClass(): string {
        return $this->badgeClass();
    }

    /**
     * Human-readable label.
     */
    public function label(): string {
        return match ($this) {
            self::HEALTHY  => 'Healthy',
            self::DEGRADED => 'Degraded',
            self::WARNING  => 'Attention Required',
            self::CRITICAL => 'Critical Compromise',
            self::DISABLED => 'Disabled / Inactive',
            self::UNKNOWN  => 'Unknown / Not Probed',
        };
    }
}
