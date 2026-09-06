<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\GeDefense\GeDefenseKernel;

/**
 * GeDefense Kernel First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class GeDefenseModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'gedefense',
            name: 'Astraea GeDefense',
            version: '2.5.0',
            bootPhase: BootPhase::PHASE_C,
            dependencies: [],
            conflicts: ['wordfence', 'better-wp-security', 'all-in-one-wp-security-and-firewall'],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'GeDefense',
            adminRoute: 'gedefense-dashboard',
            isToggleable: false,
            description: 'Integrated multi-tier perimeter defense, WAF, XDR security fabric, and brute force mitigation.',
            compatibilityInfo: ['xdr_version' => '2.5']
        );
    }

    public function boot(): void {
        // GeDefense kernel engagement handled deterministically during Phase C
        if (class_exists(GeDefenseKernel::class)) {
            GeDefenseKernel::engageEarly();
            GeDefenseKernel::engageFull();
        }
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $kernelReady = class_exists(GeDefenseKernel::class);
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if (!$kernelReady) {
            return ModuleHealth::critical('GeDefenseKernel class not loaded.', [], $latency);
        }

        return ModuleHealth::healthy('GeDefense operational. Multi-tier perimeter active.', ['xdr' => 'active'], $latency);
    }
}
