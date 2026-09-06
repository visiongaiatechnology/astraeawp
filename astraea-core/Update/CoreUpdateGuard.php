<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Update;

use Astraea\Version;

/**
 * Core Update Shield for AstraeaOS WP.
 *
 * Prevents upstream WordPress repositories from overwriting or downgrading
 * the AstraeaOS Core Fork.
 *
 * @package Astraea\Update
 */
final class CoreUpdateGuard {

    public static function init(): void {
        // Block upstream WordPress core updates from being detected or offered
        add_filter('pre_set_site_transient_update_core', [self::class, 'filterUpdateCoreTransient'], 9999);
        add_filter('site_transient_update_core', [self::class, 'filterUpdateCoreTransient'], 9999);

        // Completely disable automatic upstream WordPress core updates
        add_filter('auto_update_core', '__return_false', 9999);
        add_filter('allow_major_auto_core_updates', '__return_false', 9999);
        add_filter('allow_minor_auto_core_updates', '__return_false', 9999);
        add_filter('allow_dev_auto_core_updates', '__return_false', 9999);

        // Suppress upstream WordPress core version check HTTP request
        add_filter('pre_http_request', [self::class, 'blockUpstreamCoreRequests'], 10, 3);
    }

    /**
     * Purges upstream core updates so WordPress never downloads or prompts for an upstream core upgrade.
     *
     * @param mixed $transient
     * @return object
     */
    public static function filterUpdateCoreTransient(mixed $transient): object {
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }

        $transient->updates = [];
        $transient->version_checked = Version::VERSION;
        $transient->last_checked = time();

        return $transient;
    }

    /**
     * Block requests to api.wordpress.org for core updates.
     */
    public static function blockUpstreamCoreRequests(mixed $preempt, array $parsedArgs, string $url): mixed {
        if (str_contains($url, 'api.wordpress.org/core/version-check')) {
            // Return synthetic empty response
            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body'     => json_encode([
                    'offers'       => [],
                    'translations' => [],
                ], JSON_THROW_ON_ERROR),
                'headers'  => [],
                'cookies'  => [],
            ];
        }

        return $preempt;
    }
}
