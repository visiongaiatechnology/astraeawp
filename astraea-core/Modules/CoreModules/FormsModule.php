<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Forms\SubmissionProcessor;
use Astraea\Forms\FormRenderer;
use Astraea\Forms\FormsAdmin;
use Astraea\Crypto\Keyring;
use Astraea\Crypto\KeyContext;

/**
 * Astraea Forms Light First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class FormsModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'forms',
            name: 'Astraea Forms Light',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: [],
            conflicts: ['contact-form-7', 'wpforms-lite', 'ninja-forms', 'gravityforms'],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Forms',
            adminRoute: 'astraea-forms',
            isToggleable: true,
            description: 'Hardened form engine with honeypot trap, IP rate limiter, and AEAD encrypted submission storage.',
            compatibilityInfo: ['encrypted_storage' => true]
        );
    }

    public function boot(): void {
        SubmissionProcessor::init();
        FormRenderer::init();
        FormsAdmin::init();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $keyAvailable = Keyring::isInitialized();
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if (!$keyAvailable) {
            return ModuleHealth::critical('Keyring not initialized; encrypted form storage unavailable.', [], $latency);
        }

        return ModuleHealth::healthy('Astraea Forms Light ready with AEAD submission encryption.', [], $latency);
    }
}
