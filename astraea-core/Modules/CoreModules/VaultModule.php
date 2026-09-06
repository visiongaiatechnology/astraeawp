<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;

/**
 * Vault First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class VaultModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'vault',
            name: 'Astraea Vault',
            version: '2.0.0',
            bootPhase: BootPhase::PHASE_D,
            dependencies: [],
            conflicts: ['updraftplus', 'backwpup', 'duplicator'],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Vault',
            adminRoute: 'astraea-vault',
            isToggleable: false,
            description: 'Core-native immutable snapshot engine, quarantine barrier, and transactional rollback.',
            compatibilityInfo: ['avb_format' => 'v2']
        );
    }

    public function boot(): void {
        if (class_exists('\\Astraea\\Vault\\Plugin')) {
            \Astraea\Vault\Plugin::instance()->boot();
        }
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $vaultClass = class_exists('\\Astraea\\Vault\\Plugin');
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if (!$vaultClass) {
            return ModuleHealth::critical('Astraea Vault plugin class not loaded.', [], $latency);
        }

        return ModuleHealth::healthy('Astraea Vault online and guarding core updates and state changes.', [], $latency);
    }
}
