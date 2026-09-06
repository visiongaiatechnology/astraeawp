<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Performance;

/**
 * Modern Browser Cache Policy & HTTP Header Controller.
 *
 * Enforces immutable caching for versioned core assets and strict
 * Cache-Control / ETag policies for authenticated vs public contexts.
 *
 * @package Astraea\Performance
 */
final class BrowserCachePolicy {

    public static function init(): void {
        add_action('send_headers', [self::class, 'applyHeaders']);
    }

    public static function applyHeaders(): void {
        if (headers_sent()) {
            return;
        }

        // Authenticated or Admin areas: strict no-store
        if (is_admin() || is_user_logged_in()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
            header('Pragma: no-cache');
            header('Expires: 0');
            return;
        }

        // REST API: no-store
        if (defined('REST_REQUEST') && REST_REQUEST) {
            header('Cache-Control: no-cache, must-revalidate, max-age=0, private');
            return;
        }

        // Public static page: allow short browser cache with revalidation
        if (!PageCache::shouldBypass()) {
            header('Cache-Control: public, max-age=600, stale-while-revalidate=60');
        }
    }

    /**
     * Set immutable caching headers for versioned Astraea assets.
     */
    public static function applyImmutableAssetHeaders(): void {
        if (!headers_sent()) {
            header('Cache-Control: public, max-age=31536000, immutable');
        }
    }
}
