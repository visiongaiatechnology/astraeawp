<?php
declare(strict_types=1);

namespace Astraea\GeDefense;

use VIS_Bootstrapper;
use VIS_Schema;

/**
 * AstraeaOS First-Party GeDefense Kernel Orchestrator.
 *
 * Promotes GeDefense from an external plugin to a native AstraeaOS core module.
 * Executes early security pre-flight before database connections or plugin hooks.
 *
 * Boot Order:
 * 1. Environment & Baseline
 * 2. Astraea Bootstrap & Crypto
 * 3. GeDefense Early Pre-Flight (Cerberus L0, Zeus, Aegis, Hades)
 * 4. Database & Core Hooks
 * 5. GeDefense Invariant & Hardening (Phase 2)
 *
 * @package Astraea\GeDefense
 */
final class GeDefenseKernel {

    private static bool $booted = false;

    /**
     * Initialize constants and autoloader for GeDefense.
     */
    public static function boot(): void {
        if (self::$booted) {
            return;
        }

        if (!defined('VIS_VERSION')) {
            define('VIS_VERSION', '8.1.0-astraea');
        }
        if (!defined('VIS_MANIFEST_DIGEST')) {
            define('VIS_MANIFEST_DIGEST', '64d3be758103abdd185d5453bb7f8ff8712af1f1296ec6b03c3f6a6fc6f3b0a5');
        }
        if (!defined('VIS_PRODUCT_NAME')) {
            define('VIS_PRODUCT_NAME', 'GeDefense WP — AstraeaOS Core Security Kernel');
        }
        if (!defined('VIS_PATH')) {
            define('VIS_PATH', __DIR__ . '/');
        }
        if (!defined('VIS_URL')) {
            define('VIS_URL', defined('WP_CONTENT_URL') ? WP_CONTENT_URL . '/../astraea-core/GeDefense/' : '/astraea-core/GeDefense/');
        }
        if (!defined('VIS_SENTINEL_ICON')) {
            define('VIS_SENTINEL_ICON', VIS_URL . 'Sentinel.png');
        }
        if (!defined('VIS_TABLE_BANS')) {
            define('VIS_TABLE_BANS', 'vis_apex_bans');
        }
        if (!defined('VIS_TABLE_LOGS')) {
            define('VIS_TABLE_LOGS', 'vis_omega_logs');
        }

        if (!defined('VIS_VAULT_DIR')) {
            if (defined('WP_CONTENT_DIR')) {
                define('VIS_VAULT_DIR', WP_CONTENT_DIR . '/uploads/vis-vault-omega');
            } else {
                define('VIS_VAULT_DIR', __DIR__ . '/vault-storage');
            }
        }

        // Register GeDefense internal autoloader and security primitives
        require_once VIS_PATH . 'class-vis-bootstrapper.php';
        require_once VIS_PATH . 'includes/core/class-vis-security.php';
        VIS_Bootstrapper::register_autoloader();

        if (class_exists('\\VisionGaia\\GeDefense\\Core\\EventBus')) {
            \VisionGaia\GeDefense\Core\EventBus::init();
        }

        self::$booted = true;
    }

    /**
     * Engage early pre-flight kernel (Phase 1).
     * Runs in Phase C after $wpdb and object cache are ready.
     */
    public static function engageEarly(): void {
        self::boot();

        if (class_exists('\\VIS_Bootstrapper') && \VIS_Bootstrapper::is_installing()) {
            return;
        }

        if (!function_exists('wp_cache_get') || !function_exists('get_option')) {
            return;
        }

        $config = [];
        global $wpdb;
        if (isset($wpdb) && ($wpdb instanceof \wpdb) && !empty($wpdb->dbh)) {
            // Auto-enforce GeDefense database schema before Cerberus accesses tables
            if (get_option('vis_db_version') !== VIS_VERSION) {
                require_once VIS_PATH . 'class-vis-schema.php';
                if (class_exists('VIS_Schema')) {
                    \VIS_Schema::enforce();
                }
            }

            $raw = get_option('vis_config', []);
            if (is_array($raw)) {
                $config = $raw;
            }
        }

        VIS_Bootstrapper::engage_phase_1($config);
    }

    /**
     * Engage full hardening and admin dashboard (Phase 2).
     */
    public static function engageFull(): void {
        self::boot();

        if (class_exists('\\VIS_Bootstrapper') && \VIS_Bootstrapper::is_installing()) {
            return;
        }

        if (!function_exists('wp_cache_get') || !function_exists('get_option')) {
            return;
        }

        $config = [];
        global $wpdb;
        if (isset($wpdb) && ($wpdb instanceof \wpdb) && !empty($wpdb->dbh)) {
            // Auto-enforce GeDefense database schema if still pending
            if (get_option('vis_db_version') !== VIS_VERSION) {
                require_once VIS_PATH . 'class-vis-schema.php';
                if (class_exists('VIS_Schema')) {
                    \VIS_Schema::enforce();
                }
            }

            $raw = get_option('vis_config', []);
            if (is_array($raw)) {
                $config = $raw;
            }
        }

        VIS_Bootstrapper::engage_phase_2($config);
    }
}
