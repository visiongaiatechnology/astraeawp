<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Compatibility\CompatibilityManager;
use Astraea\Compatibility\CompatibilityAdmin;

/**
 * Astraea Compatibility Layer First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class CompatibilityModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'compatibility',
            name: 'Astraea Compatibility Layer',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: [],
            conflicts: [],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Compatibility',
            adminRoute: 'astraea-compatibility',
            isToggleable: true,
            description: 'Granular compatibility flags for legacy plugin interoperability without globally weakening security.',
            compatibilityInfo: ['granular_exceptions' => true]
        );
    }

    public function boot(): void {
        CompatibilityManager::init();
        CompatibilityAdmin::init();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $flags = CompatibilityManager::getFlags();
        $activeCount = count(array_filter($flags));
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if ($activeCount > 0) {
            return ModuleHealth::degraded(
                sprintf('%d compatibility exception(s) active. Security posture slightly relaxed for legacy plugins.', $activeCount),
                ['active_flags' => $flags],
                $latency
            );
        }

        return ModuleHealth::healthy('Compatibility layer nominal. Zero legacy exceptions active.', [], $latency);
    }
}
