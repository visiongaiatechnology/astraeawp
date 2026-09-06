<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\SEO;

/**
 * Third-Party SEO Plugin Conflict Detector.
 *
 * Suppresses Astraea SEO meta tags when external SEO engines (Yoast, Rank Math,
 * AIOSEO, SEOPress) are active to prevent harmful duplicate metadata emission.
 *
 * @package Astraea\SEO
 */
final class ConflictDetector {

    /**
     * Check if a third-party SEO plugin is currently active.
     *
     * @return array{has_conflict: bool, plugin_name: ?string}
     */
    public static function check(): array {
        if (defined('WPSEO_VERSION')) {
            return ['has_conflict' => true, 'plugin_name' => 'Yoast SEO'];
        }
        if (defined('RANK_MATH_VERSION')) {
            return ['has_conflict' => true, 'plugin_name' => 'Rank Math SEO'];
        }
        if (defined('AIOSEO_VERSION')) {
            return ['has_conflict' => true, 'plugin_name' => 'All in One SEO'];
        }
        if (defined('SEOPRESS_VERSION')) {
            return ['has_conflict' => true, 'plugin_name' => 'SEOPress'];
        }

        return ['has_conflict' => false, 'plugin_name' => null];
    }

    public static function hasConflict(): bool {
        return self::check()['has_conflict'];
    }

    /**
     * Return list of active conflicting third-party SEO plugin names.
     *
     * @return string[]
     */
    public static function detectConflicts(): array {
        $result = self::check();
        return ($result['has_conflict'] && $result['plugin_name'] !== null) ? [$result['plugin_name']] : [];
    }
}
