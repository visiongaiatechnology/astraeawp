<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Maintenance\MaintenanceController;
use Astraea\Maintenance\MaintenanceAdmin;
use Astraea\Maintenance\MaintenanceMode;

/**
 * Astraea Maintenance Mode First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class MaintenanceModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'maintenance',
            name: 'Astraea Maintenance Mode',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: [],
            conflicts: ['under-construction-page', 'wp-maintenance-mode', 'seedprod-coming-soon'],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Maintenance',
            adminRoute: 'astraea-maintenance',
            isToggleable: true,
            description: 'SEO-safe 503 maintenance mode with administrator bypass and glassmorphism template.',
            compatibilityInfo: ['seo_503_safe' => true]
        );
    }

    public function boot(): void {
        MaintenanceController::init();
        MaintenanceAdmin::init();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $config = MaintenanceController::getConfig();
        $mode = $config['mode'] ?? 'disabled';
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        return ModuleHealth::healthy(
            sprintf('Maintenance mode active (Current mode: %s).', $mode),
            ['mode' => $mode],
            $latency
        );
    }
}
