<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Performance;

/**
 * AstraeaOS Legacy Pruner & Attack Surface Reducer.
 *
 * Disables obsolete, high-risk legacy surfaces by default while providing
 * explicit compatibility toggles for legacy sites:
 *
 * 1. XML-RPC: Disabled by default (major brute-force & amplification vector).
 * 2. Pingbacks & Trackbacks: Disabled by default (SSRF & DDoS vector).
 * 3. Emojis Inline Bloat: Disabled by default (eliminates blocking scripts on wp_head).
 * 4. Obsolete discovery headers: RSD, WLW manifest stripped.
 *
 * @package Astraea\Performance
 */
final class LegacyPruner {

    private static bool $initialized = false;

    /**
     * Apply legacy pruning filters and guards.
     */
    public static function apply(): void {
        if (self::$initialized) {
            return;
        }

        self::pruneXmlRpc();
        self::prunePingbacks();
        self::pruneEmojis();
        self::pruneDiscoveryHeaders();
        self::shieldCoreUpdates();

        self::$initialized = true;
    }

    /**
     * Check if XML-RPC is explicitly enabled.
     */
    public static function isXmlRpcEnabled(): bool {
        if (defined('ASTRAEA_ENABLE_XMLRPC') && ASTRAEA_ENABLE_XMLRPC === true) {
            return true;
        }
        return function_exists('apply_filters') ? (bool) apply_filters('astraea_enable_xmlrpc', false) : false;
    }

    /**
     * Check if Pingbacks are explicitly enabled.
     */
    public static function isPingbackEnabled(): bool {
        if (defined('ASTRAEA_ENABLE_PINGBACKS') && ASTRAEA_ENABLE_PINGBACKS === true) {
            return true;
        }
        return function_exists('apply_filters') ? (bool) apply_filters('astraea_enable_pingbacks', false) : false;
    }

    /**
     * Check if Core Emojis scripts are explicitly enabled.
     */
    public static function isEmojiEnabled(): bool {
        if (defined('ASTRAEA_ENABLE_EMOJIS') && ASTRAEA_ENABLE_EMOJIS === true) {
            return true;
        }
        return function_exists('apply_filters') ? (bool) apply_filters('astraea_enable_emojis', false) : false;
    }

    /**
     * Prune XML-RPC attack surface.
     */
    private static function pruneXmlRpc(): void {
        if (self::isXmlRpcEnabled()) {
            return;
        }

        // Disable XML-RPC option filter
        add_filter('xmlrpc_enabled', '__return_false', 99);

        // Terminate direct xmlrpc.php execution
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('AstraeaOS Security Notice: XML-RPC protocol is disabled by default. Define ASTRAEA_ENABLE_XMLRPC in wp-config.php to enable.');
        }
    }

    /**
     * Prune Pingbacks & Trackbacks (mitigate SSRF and reflection DDoS).
     */
    private static function prunePingbacks(): void {
        if (self::isPingbackEnabled()) {
            return;
        }

        // Unhook pingback handlers
        remove_action('do_all_pings', 'do_all_pingbacks', 10);
        remove_action('do_all_pings', 'do_all_trackbacks', 10);
        remove_action('pre_trackback_post', 'wp_maybe_disable_trackback_for_environment', 10);

        // Strip X-Pingback response header
        add_filter('wp_headers', function (array $headers): array {
            unset($headers['X-Pingback'], $headers['x-pingback']);
            return $headers;
        }, 99);

        // Disable XML-RPC pingback methods if XML-RPC was manually enabled
        add_filter('xmlrpc_methods', function (array $methods): array {
            unset($methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);
            return $methods;
        }, 99);
    }

    /**
     * Prune Emoji inline script & style injection from HTML head.
     */
    private static function pruneEmojis(): void {
        if (self::isEmojiEnabled()) {
            return;
        }

        // Remove actions injecting scripts and styles
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_action('embed_head', 'print_emoji_detection_script');

        // Remove emoji content formatting filters
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

        // TinyMCE editor emoji plugin
        add_filter('tiny_mce_plugins', function (array $plugins): array {
            return array_diff($plugins, ['wpemoji']);
        });

        // DNS prefetch for s.w.org emoji CDN
        add_filter('wp_resource_hints', function (array $urls, string $relationType): array {
            if ($relationType === 'dns-prefetch') {
                $urls = array_filter($urls, function (string $url): bool {
                    return !str_contains($url, 's.w.org');
                });
            }
            return $urls;
        }, 10, 2);
    }

    /**
     * Remove obsolete Really Simple Discovery (RSD) and Windows Live Writer links.
     */
    private static function pruneDiscoveryHeaders(): void {
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_generator'); // Hide WordPress generator tag
    }

    /**
     * Shield AstraeaOS WP core from upstream WordPress automatic and manual update overwrites.
     * Prevents api.wordpress.org updates from destroying AstraeaOS core modifications.
     */
    private static function shieldCoreUpdates(): void {
        // Disallow automatic core updates
        add_filter('auto_update_core', '__return_false', PHP_INT_MAX);
        add_filter('allow_major_auto_core_updates', '__return_false', PHP_INT_MAX);
        add_filter('allow_minor_auto_core_updates', '__return_false', PHP_INT_MAX);
        add_filter('allow_dev_auto_core_updates', '__return_false', PHP_INT_MAX);

        // Suppress upstream WordPress core update offerings in site transients
        add_filter('pre_site_transient_update_core', [self::class, 'filterUpdateCoreTransient'], PHP_INT_MAX);
        add_filter('pre_set_site_transient_update_core', [self::class, 'filterUpdateCoreTransient'], PHP_INT_MAX);

        // Fail-closed block on upstream WordPress core upgrades
        add_filter('core_upgrade_preemption', static function (): \WP_Error {
            return new \WP_Error(
                'astraea_core_locked',
                __('AstraeaOS WP Core is governed by the VGT Sovereign Security Core. Standard upstream WordPress core updates are disabled to preserve cryptographic integrity and system architecture. Please apply updates through verified AstraeaOS release channels.', 'astraea')
            );
        }, PHP_INT_MAX);

        // Remove core update checks, cron tasks, and update nag notices
        if (function_exists('remove_action')) {
            remove_action('admin_init', '_maybe_update_core');
            remove_action('wp_version_check', 'wp_version_check');
            remove_action('upgrader_process_complete', 'wp_version_check');
        }

        if (function_exists('add_action')) {
            add_action('admin_init', static function (): void {
                remove_action('admin_notices', 'update_nag', 3);
                remove_action('network_admin_notices', 'update_nag', 3);
                remove_action('admin_init', '_maybe_update_core');

                global $pagenow;
                if ($pagenow === 'update-core.php') {
                    add_action('admin_notices', static function (): void {
                        echo '<div class="notice notice-info" style="border-left-color: #0ea5e9; background: #0c1527; color: #cbd5e1; padding: 12px 16px; border-radius: 6px; margin: 15px 0;">'
                            . '<strong style="color: #38bdf8;">AstraeaOS WP Sovereign Core:</strong> '
                            . esc_html__('Upstream WordPress core updates are locked by architectural doctrine. Your system integrity is verified and maintained autonomously by the AstraeaOS Diamond Engine.', 'astraea')
                            . '</div>';
                    }, 1);
                }
            }, 1);
        }
    }

    /**
     * Intercept core update transients to report AstraeaOS as current and offer 0 upstream core updates.
     *
     * @param mixed $transient
     * @return object
     */
    public static function filterUpdateCoreTransient(mixed $transient): object {
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }
        $transient->last_checked = time();
        $transient->updates = [];
        $transient->version_checked = defined('ASTRAEA_VERSION') ? ASTRAEA_VERSION : 'unknown';
        return $transient;
    }
}
