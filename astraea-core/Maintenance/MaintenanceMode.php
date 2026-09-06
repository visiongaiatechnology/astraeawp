<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Maintenance;

/**
 * Maintenance Mode States.
 *
 * @package Astraea\Maintenance
 */
enum MaintenanceMode: string {
    case DISABLED     = 'disabled';
    case MAINTENANCE  = 'maintenance';  // Emits HTTP 503 Service Unavailable (SEO Safe)
    case COMING_SOON  = 'coming_soon';   // Emits HTTP 200 OK
}
