<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\VLP\Light\Kernel as VLPLightKernel;

/**
 * VLP Light First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class VLPModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'vlp',
            name: 'Astraea VLP Light',
            version: '1.2.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: ['vault'],
            conflicts: ['complianz-gdpr', 'cookie-law-info', 'borlabs-cookie'],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'VLP',
            adminRoute: 'astraea-vlp-settings',
            isToggleable: true,
            description: 'Kernel-native consent receipt ledger, DOM gatekeeper, asset scanner, and privacy enforcement.',
            compatibilityInfo: ['consent_format' => 'jwt_hmac']
        );
    }

    public function boot(): void {
        VLPLightKernel::boot();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $vlpReady = class_exists(VLPLightKernel::class);
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if (!$vlpReady) {
            return ModuleHealth::critical('VLP Light Kernel class missing.', [], $latency);
        }

        return ModuleHealth::healthy('VLP Light consent engine and DOM gate active.', [], $latency);
    }
}
