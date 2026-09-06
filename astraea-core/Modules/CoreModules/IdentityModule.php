<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Auth\IdentityCenter;

/**
 * Astraea Identity Center First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class IdentityModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'identity',
            name: 'Astraea Identity Center',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: [],
            conflicts: ['wp-2fa', 'two-factor', 'wp-security-audit-log'],
            requiredCapabilities: ['read'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Auth',
            adminRoute: 'astraea-identity',
            isToggleable: true,
            description: 'Active session management, privacy-preserving login history, and multi-factor recovery codes.',
            compatibilityInfo: ['argon2id_passwords' => true]
        );
    }

    public function boot(): void {
        IdentityCenter::init();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $sessions = IdentityCenter::getActiveSessions();
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        return ModuleHealth::healthy(
            sprintf('Identity Center active. %d active session(s) tracked.', count($sessions)),
            ['sessions_count' => count($sessions)],
            $latency
        );
    }
}
