<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Performance\PageCache;
use Astraea\Performance\BrowserCachePolicy;
use Astraea\Performance\MediaLazyLoader;
use Astraea\Performance\HeartbeatController;
use Astraea\Performance\PerformanceAdmin;

/**
 * Astraea Performance Engine First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class PerformanceModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'performance',
            name: 'Astraea Performance Engine',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: [],
            conflicts: ['wp-super-cache', 'w3-total-cache', 'wp-rocket', 'litespeed-cache', 'autoptimize'],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Performance',
            adminRoute: 'astraea-performance',
            isToggleable: true,
            description: 'Kernel page cache, asset caching policies, native lazy loading, and database autoload profiling.',
            compatibilityInfo: ['page_cache' => true]
        );
    }

    public function boot(): void {
        PageCache::init();
        BrowserCachePolicy::init();
        MediaLazyLoader::init();
        HeartbeatController::init();
        PerformanceAdmin::init();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $cacheDir = WP_CONTENT_DIR . '/cache/astraea-page-cache';
        $writable = is_dir($cacheDir) ? is_writable($cacheDir) : is_writable(dirname($cacheDir));
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if (!$writable) {
            return ModuleHealth::degraded('Page cache directory is not writable by web server.', ['cache_dir' => $cacheDir], $latency);
        }

        return ModuleHealth::healthy('Performance engine operational; page cache ready.', ['cache_dir' => $cacheDir], $latency);
    }
}
