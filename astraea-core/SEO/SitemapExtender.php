<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\SEO;

/**
 * Native WordPress Core Sitemap Enhancer.
 *
 * Integrates directly with core WP_Sitemaps provider infrastructure,
 * enabling post type exclusions and suppression of thin author sitemaps.
 *
 * @package Astraea\SEO
 */
final class SitemapExtender {

    public static function init(): void {
        add_filter('wp_sitemaps_add_provider', [self::class, 'filterProviders'], 10, 2);
    }

    /**
     * Suppress user/author sitemaps if author archives are noindexed.
     *
     * @param mixed $provider
     * @param string $name
     * @return mixed
     */
    public static function filterProviders(mixed $provider, string $name): mixed {
        if ($name === 'users') {
            if (get_option('astraea_seo_noindex_archives', true)) {
                return false; // Suppress users sitemap provider cleanly
            }
        }
        return $provider;
    }
}
