<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Database\DatabaseAdmin;
use Astraea\Database\MaintenanceAnalyzer;

/**
 * Astraea Database Maintenance First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class DatabaseModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'database',
            name: 'Astraea Database Maintenance',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: ['vault'],
            conflicts: ['wp-optimize', 'advanced-database-cleaner', 'wp-sweep'],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Database',
            adminRoute: 'astraea-database',
            isToggleable: true,
            description: 'Safe database maintenance, revision pruner, spam/trash cleaner with mandatory Vault snapshot.',
            compatibilityInfo: ['vault_snapshot_required' => true]
        );
    }

    public function boot(): void {
        DatabaseAdmin::init();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        global $wpdb;
        $connected = isset($wpdb) && $wpdb instanceof \wpdb && !empty($wpdb->dbh);
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if (!$connected) {
            return ModuleHealth::critical('Database connection not available.', [], $latency);
        }

        return ModuleHealth::healthy('Database connection healthy.', [], $latency);
    }
}
