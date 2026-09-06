<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Redirects\RedirectEngine;
use Astraea\Redirects\SlugChangeWatcher;
use Astraea\Redirects\NotFoundMonitor;
use Astraea\Redirects\RedirectAdmin;

/**
 * Astraea Redirect Manager First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class RedirectsModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'redirects',
            name: 'Astraea Redirect Manager',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: [],
            conflicts: ['redirection', 'safe-redirect-manager', '301-redirects'],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Redirects',
            adminRoute: 'astraea-redirects',
            isToggleable: true,
            description: 'Deterministic 301/302 redirect engine with ReDoS protection, loop detection, and 404 telemetry.',
            compatibilityInfo: ['loop_protection' => true]
        );
    }

    public function boot(): void {
        RedirectEngine::init();
        SlugChangeWatcher::init();
        NotFoundMonitor::init();
        RedirectAdmin::init();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $rules = RedirectEngine::getRules();
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        return ModuleHealth::healthy(
            sprintf('Redirect manager active. %d rule(s) configured.', count($rules)),
            ['rule_count' => count($rules)],
            $latency
        );
    }
}
