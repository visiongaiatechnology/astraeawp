<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Update\CoreUpdateGuard;
use Astraea\Update\UpdateAdmin;
use Astraea\Version;

/**
 * Astraea Update Engine First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class UpdateModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'update',
            name: 'Astraea Update Engine',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: ['vault'],
            conflicts: ['easy-updates-manager'],
            requiredCapabilities: ['update_core'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Update',
            adminRoute: 'astraea-updates',
            isToggleable: false,
            description: 'Cryptographically signed Ed25519 distribution updates and upstream WordPress overwrite shield.',
            compatibilityInfo: ['ed25519_signed' => true]
        );
    }

    public function boot(): void {
        CoreUpdateGuard::init();
        UpdateAdmin::init();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $manifestPath = dirname(__DIR__, 2) . '/BUILD-MANIFEST.json';
        $manifestExists = file_exists($manifestPath);
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if (!$manifestExists) {
            return ModuleHealth::degraded('Root BUILD-MANIFEST.json not found.', [], $latency);
        }

        return ModuleHealth::healthy(
            sprintf('Update engine ready (Astraea version: %s). Upstream overwrites shielded.', Version::VERSION),
            ['version' => Version::VERSION],
            $latency
        );
    }
}
