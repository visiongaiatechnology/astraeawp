<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\SEO\MetaRenderer;
use Astraea\SEO\SchemaGenerator;
use Astraea\SEO\SitemapExtender;
use Astraea\SEO\ConflictDetector;
use Astraea\SEO\SeoAdmin;

/**
 * Astraea SEO Essentials First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class SeoModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'seo',
            name: 'Astraea SEO Essentials',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: [],
            conflicts: ['wordpress-seo', 'seo-by-rank-math', 'all-in-one-seo-pack', 'wp-seopress'],
            requiredCapabilities: ['manage_options'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'SEO',
            adminRoute: 'astraea-seo',
            isToggleable: true,
            description: 'Native canonicals, OpenGraph, JSON-LD Schema structured data, and conflict-aware sitemaps.',
            compatibilityInfo: ['conflict_detection' => true]
        );
    }

    public function boot(): void {
        MetaRenderer::init();
        SchemaGenerator::init();
        SitemapExtender::init();
        SeoAdmin::init();
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);
        $conflicts = ConflictDetector::detectConflicts();
        $latency = (int)round((hrtime(true) - $start) / 1e6);

        if (!empty($conflicts)) {
            return ModuleHealth::degraded(
                sprintf('Third-party SEO plugin active (%s). Astraea SEO tags suppressed to avoid duplication.', implode(', ', $conflicts)),
                ['conflicts' => $conflicts],
                $latency
            );
        }

        return ModuleHealth::healthy('Astraea SEO active; emitting authoritative technical meta.', [], $latency);
    }
}
