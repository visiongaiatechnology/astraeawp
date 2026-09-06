<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Recovery\BootFailureDetector;

/**
 * Recovery Subsystem First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class RecoveryModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'recovery',
            name: 'Astraea Recovery Gate',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_A,
            dependencies: [],
            conflicts: [],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Recovery',
            adminRoute: null,
            isToggleable: false,
            description: 'Autonomous boot loop detector and emergency recovery console.',
            compatibilityInfo: ['pure_php' => true]
        );
    }

    public function boot(): void {
        BootFailureDetector::trackBootStart();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $count = BootFailureDetector::getFailureCount();
        $isEngaged = BootFailureDetector::isRecoveryRecommended();
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if ($isEngaged) {
            return ModuleHealth::critical(
                sprintf('Recovery gate engaged. %d consecutive boot failure(s) recorded.', $count),
                ['failure_count' => $count],
                $latency
            );
        }

        if ($count > 0) {
            return ModuleHealth::degraded(
                sprintf('%d non-critical boot failure(s) detected.', $count),
                ['failure_count' => $count],
                $latency
            );
        }

        return ModuleHealth::healthy('Recovery gate standing by; boot telemetry clean.', ['failure_count' => 0], $latency);
    }
}
