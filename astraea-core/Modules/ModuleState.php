<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules;

/**
 * Lifecycle and execution states for Astraea First-Party Modules.
 *
 * @package Astraea\Modules
 */
enum ModuleState: string {
    case UNBOOTED = 'UNBOOTED';
    case BOOTING  = 'BOOTING';
    case ACTIVE   = 'ACTIVE';
    case BLOCKED  = 'BLOCKED';
    case FAILED   = 'FAILED';
    case DISABLED = 'DISABLED';

    public function isOperational(): bool {
        return $this === self::ACTIVE;
    }

    public function isBlockedOrFailed(): bool {
        return $this === self::BLOCKED || $this === self::FAILED;
    }
}
