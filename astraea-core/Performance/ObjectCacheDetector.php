<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Performance;

/**
 * Native Persistent Object Cache Detector.
 *
 * Detects APCu, Redis, and Memcached backends without enforcing external SaaS dependencies.
 *
 * @package Astraea\Performance
 */
final class ObjectCacheDetector {

    /**
     * Inspect host runtime for available or active object caching layers.
     *
     * @return array{status: string, backend: string, persistent: bool, details: string}
     */
    public static function detect(): array {
        // 1. Check if WordPress core is using an external drop-in object-cache.php
        $wpExternal = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

        if ($wpExternal) {
            return [
                'status'     => 'ACTIVE_PERSISTENT',
                'backend'    => 'WordPress External Drop-in',
                'persistent' => true,
                'details'    => 'Persistent object cache drop-in (object-cache.php) is active in wp-content.',
            ];
        }

        // 2. Check for APCu
        if (extension_loaded('apcu') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return [
                'status'     => 'AVAILABLE',
                'backend'    => 'APCu (Shared Memory)',
                'persistent' => true,
                'details'    => 'PHP APCu extension is loaded and active for in-memory object caching.',
            ];
        }

        // 3. Check for Redis extension
        if (extension_loaded('redis')) {
            return [
                'status'     => 'AVAILABLE',
                'backend'    => 'PHP Redis Extension',
                'persistent' => false,
                'details'    => 'PHP Redis driver is loaded on the host, but no active drop-in is configured.',
            ];
        }

        // 4. Check for Memcached extension
        if (extension_loaded('memcached')) {
            return [
                'status'     => 'AVAILABLE',
                'backend'    => 'PHP Memcached Extension',
                'persistent' => false,
                'details'    => 'PHP Memcached driver is loaded on the host, but no active drop-in is configured.',
            ];
        }

        return [
            'status'     => 'EPHEMERAL',
            'backend'    => 'WordPress Core In-Memory (Non-Persistent)',
            'persistent' => false,
            'details'    => 'Standard single-request memory cache active. No persistent cache backend configured.',
        ];
    }
}
