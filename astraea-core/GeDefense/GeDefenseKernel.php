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
            define('VIS_VERSION', '8.6.0-astraea');
        }
        if (!defined('VIS_MANIFEST_DIGEST')) {
            define('VIS_MANIFEST_DIGEST', '9c3403ed7a8bfa8865669b51cdd60febd213d081d9025ea8bfca1c67d161a78c');
        }
        if (!defined('VIS_PRODUCT_NAME')) {
            define('VIS_PRODUCT_NAME', 'GeDefense WP 8.6.0 — AstraeaOS Security Kernel');
        }
        if (!defined('VIS_GORGON_NEXUS_MAGIC')) {
            define('VIS_GORGON_NEXUS_MAGIC', 'vgt-magic-noc-9938-omega-fusion');
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
        if (!defined('VIS_MANIFEST_FILE')) {
            define('VIS_MANIFEST_FILE', VIS_VAULT_DIR . '/integrity_matrix.json');
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
     * Guard against running GeDefense runtime hooks during WordPress / Astraea installation
     * or while AUTH_SALT / $wpdb are not yet provisioned.
     */
    private static function isInstalling(): bool {
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            return true;
        }
        if (defined('WP_SETUP_CONFIG') && WP_SETUP_CONFIG) {
            return true;
        }
        if (function_exists('wp_installing') && wp_installing()) {
            return true;
        }
        if (!defined('AUTH_SALT') || strlen((string) AUTH_SALT) < 32) {
            return true;
        }
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof \wpdb) || empty($wpdb->dbh)) {
            return true;
        }
        if (function_exists('wp_cache_get') && function_exists('is_blog_installed') && !is_blog_installed()) {
            return true;
        }
        return false;
    }

    /**
     * Engage early pre-flight kernel (Phase 1).
     * Runs in Phase C after $wpdb and object cache are ready.
     */
    public static function engageEarly(): void {
        self::boot();

        if (self::isInstalling()) {
            return;
        }

        if (!function_exists('wp_cache_get') || !function_exists('get_option')) {
            return;
        }

        $config = [];
        global $wpdb;
        if (isset($wpdb) && ($wpdb instanceof \wpdb) && !empty($wpdb->dbh)) {
            // Auto-enforce GeDefense database schema before Cerberus accesses tables.
            // VIS_Schema::enforce() checks numeric SCHEMA_VERSION internally and bails early when current.
            require_once VIS_PATH . 'class-vis-schema.php';
            if (class_exists('VIS_Schema')) {
                \VIS_Schema::enforce();
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

        if (self::isInstalling()) {
            return;
        }

        if (!function_exists('wp_cache_get') || !function_exists('get_option')) {
            return;
        }

        $config = [];
        global $wpdb;
        if (isset($wpdb) && ($wpdb instanceof \wpdb) && !empty($wpdb->dbh)) {
            require_once VIS_PATH . 'class-vis-schema.php';
            if (class_exists('VIS_Schema')) {
                \VIS_Schema::enforce();
            }

            $raw = get_option('vis_config', []);
            if (is_array($raw)) {
                $config = $raw;
            }
        }

        VIS_Bootstrapper::engage_phase_2($config);
    }
}
