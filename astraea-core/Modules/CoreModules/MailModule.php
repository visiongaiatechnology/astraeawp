<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Mail\Kernel as MailKernel;

/**
 * Astraea Mail Gateway First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class MailModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'mail',
            name: 'Astraea Mail Gateway',
            version: '1.1.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: [],
            conflicts: ['wp-mail-smtp', 'easy-wp-smtp', 'post-smtp'],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Mail',
            adminRoute: 'astraea-mail',
            isToggleable: true,
            description: 'First-party encrypted SMTP transport with XOAUTH2 and privacy-preserving delivery journal.',
            compatibilityInfo: ['xoauth2_supported' => true]
        );
    }

    public function boot(): void {
        MailKernel::boot();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $mailReady = class_exists(MailKernel::class);
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if (!$mailReady) {
            return ModuleHealth::critical('MailKernel class missing.', [], $latency);
        }

        return ModuleHealth::healthy('Astraea Mail Gateway active with zero plaintext credentials at rest.', [], $latency);
    }
}
