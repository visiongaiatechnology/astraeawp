<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules;

/**
 * Boot Phases aligned with Astraea deterministic 5-phase boot orchestration.
 *
 * @package Astraea\Modules
 */
enum BootPhase: string {
    case PHASE_A = 'PHASE_A'; // Native PHP minimal (Zero WP APIs)
    case PHASE_B = 'PHASE_B'; // WP Core Ready (functions.php, formatting.php)
    case PHASE_C = 'PHASE_C'; // DB & Options Ready ($wpdb, options)
    case PHASE_D = 'PHASE_D'; // Pre-Plugin Recovery Gate (Vault rollback)
    case PHASE_E = 'PHASE_E'; // Normal Astraea Lifecycle (plugins_loaded / init)
}
