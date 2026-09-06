<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Tasks\TasksAdmin;
use Astraea\Tasks\TaskInspector;

/**
 * Astraea Task Center First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class TasksModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'tasks',
            name: 'Astraea Task Center',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: [],
            conflicts: ['wp-crontrol', 'advanced-cron-manager'],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Tasks',
            adminRoute: 'astraea-tasks',
            isToggleable: true,
            description: 'WP-Cron and Astraea task inspection, overdue task warnings, and manual execution triggers.',
            compatibilityInfo: ['cron_telemetry' => true]
        );
    }

    public function boot(): void {
        TasksAdmin::init();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $tasks = TaskInspector::inspect();
        $overdue = 0;
        foreach ($tasks as $task) {
            if ($task['is_overdue'] ?? false) {
                $overdue++;
            }
        }
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if ($overdue > 3) {
            return ModuleHealth::degraded(
                sprintf('%d cron task(s) are overdue (>10 min delay).', $overdue),
                ['overdue_count' => $overdue],
                $latency
            );
        }

        return ModuleHealth::healthy('Task center active; cron schedule nominal.', ['task_count' => count($tasks)], $latency);
    }
}
