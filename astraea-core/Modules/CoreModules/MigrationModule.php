<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Migration\MigrationAdmin;
use Astraea\Migration\EnvironmentScanner;

/**
 * WordPress → Astraea Migration Wizard First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class MigrationModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'migration',
            name: 'Astraea Migration Wizard',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: ['vault'],
            conflicts: [],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Migration',
            adminRoute: 'astraea-migration',
            isToggleable: true,
            description: 'Safe onboarding and migration from standard WordPress to AstraeaOS with snapshot protection.',
            compatibilityInfo: ['safe_migration' => true]
        );
    }

    public function boot(): void {
        MigrationAdmin::init();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $scan = EnvironmentScanner::scan();
        $isEligible = $scan['is_eligible'] ?? false;
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if (!$isEligible) {
            return ModuleHealth::degraded(
                'Environment scanner found warnings for Astraea migration.',
                ['warnings' => $scan['warnings'] ?? []],
                $latency
            );
        }

        return ModuleHealth::healthy('Environment fully compatible with AstraeaOS WP.', [], $latency);
    }
}
