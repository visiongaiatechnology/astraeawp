<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Bootstrap;

use Astraea\Auth\PasswordMigrationManager;
use Astraea\Crypto\Keyring;
use Astraea\Crypto\MasterKeyManager;
use Astraea\Performance\LegacyPruner;
use Astraea\Performance\RequestProfiler;
use Astraea\Security\Baseline;
use Astraea\Security\FileGuard;
use Astraea\Security\HeaderPolicyService;
use Astraea\Security\SecurityEventBridge;
use Astraea\Auth\StepUpAuthService;
use Astraea\Auth\PrivilegedActionGuard;
use Astraea\AdminUI\AdminUIBootstrap;
use Astraea\GeDefense\GeDefenseKernel;
use Astraea\Database\MigrationRunner;
use Astraea\Database\Migrations\Migration_001_InitialSchema;
use Astraea\Database\Migrations\Migration_002_VLPLight;
use Astraea\Database\Migrations\Migration_003_OperationalHardening;
use Astraea\VLP\Light\Kernel as VLPLightKernel;
use Astraea\Mail\Kernel as MailKernel;
use Astraea\Installer\SecureGenesis;
use Astraea\Modules\ModuleBootstrap;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleAdmin;
use Astraea\Recovery\BootFailureDetector;
use Astraea\Update\CoreUpdateGuard;
use Astraea\I18n\I18n;
use Astraea\I18n\LanguageManager;

/**
 * AstraeaOS Core Phased Boot Orchestrator.
 *
 * Implements the decoupled 5-phase deterministic ignition sequence:
 * - Phase A: Pre-WordPress Minimal (Native PHP only, zero WP APIs)
 * - Phase B: WordPress Core Ready (Post functions.php & formatting.php)
 * - Phase C: Database & Options Ready (Post $wpdb & options)
 * - Phase D: Pre-Plugin Recovery Gate (Astraea Vault autonomous rollback)
 * - Phase E: Normal Astraea Lifecycle (AdminUI, Control Center, Tools)
 *
 * @package Astraea\Bootstrap
 */
final class BootOrchestrator {

    private static bool $phaseADone = false;
    private static bool $phaseBDone = false;
    private static bool $phaseCDone = false;
    private static bool $phaseDDone = false;
    private static bool $phaseEDone = false;

    /**
     * Phase A: Pre-WordPress Minimal.
     * MUST execute strictly with PHP-native capabilities.
     * ZERO WordPress API calls allowed.
     */
    public static function bootPhaseA(): void {
        if (self::$phaseADone) {
            return;
        }

        if (!class_exists('\\Astraea\\Version', false)) {
            require_once dirname(__DIR__) . '/Version.php';
        }
        if (!defined('ASTRAEA_VERSION')) {
            define('ASTRAEA_VERSION', \Astraea\Version::VERSION);
        }
        if (!defined('ASTRAEA_CORE_DIR')) {
            define('ASTRAEA_CORE_DIR', dirname(__DIR__) . '/');
        }

        // 1. Assert host runtime baseline (PHP 8.3+, 64-bit, extensions)
        RuntimeCheck::assertBaseline();

        // 2. Register Autoloader
        require_once __DIR__ . '/Autoloader.php';
        Autoloader::register(dirname(__DIR__));

        // 3. Cryptographic Keyring Initialization (Pure PHP native)
        Keyring::init();

        // 4. Native Pre-Flight Fast Path: L0 IP perimeter check
        self::checkPerimeterNative();

        // 5. Track boot attempt & failure detection
        BootFailureDetector::trackBootStart();

        // 6. Execute Phase A Modules (Recovery, minimal native)
        ModuleBootstrap::bootPhase(BootPhase::PHASE_A);

        self::$phaseADone = true;
    }

    /**
     * Phase B: WordPress Core Ready.
     * Executes immediately after functions.php and formatting.php are loaded.
     */
    public static function bootPhaseB(): void {
        if (!self::$phaseADone) {
            self::bootPhaseA();
        }
        if (self::$phaseBDone) {
            return;
        }

        // 1. Procedural Cryptography & Pluggable Password Overrides
        require_once dirname(__DIR__) . '/Crypto/functions.php';
        require_once dirname(__DIR__) . '/Auth/pluggable-overrides.php';

        // 2. I18n Engine & Strict WordPress Language Firewall (9 approved languages whitelist)
        require_once dirname(__DIR__) . '/I18n/functions.php';
        I18n::init();
        LanguageManager::init();

        // 3. Password Migration Interceptor (hooks into authenticate filter)
        PasswordMigrationManager::init();

        // 3. Security Baseline & FileGuard
        Baseline::apply();
        FileGuard::init();

        // 4. Unified Header Policy Engine (HSTS safe defaults, CSP)
        HeaderPolicyService::init();

        // 5. Legacy Surface Pruning (XML-RPC, Pingbacks, Emojis)
        LegacyPruner::apply();

        // 6. Request Profiler
        RequestProfiler::start();

        // 7. GeDefense Autoloader & Constants ONLY (Pre-Flight Kernel Registration)
        // Note: Full GeDefense module execution is deferred to Phase C when $wpdb, options, and object cache are available.
        if (is_dir(dirname(__DIR__) . '/GeDefense')) {
            require_once dirname(__DIR__) . '/GeDefense/GeDefenseKernel.php';
            GeDefenseKernel::boot();
        }

        // 8. Execute Phase B Modules
        ModuleBootstrap::bootPhase(BootPhase::PHASE_B);

        self::$phaseBDone = true;
    }

    /**
     * Phase C: Database & Options Ready.
     * Executes after $wpdb, options, and object cache are initialized.
     */
    public static function bootPhaseC(): void {
        if (!self::$phaseBDone) {
            self::bootPhaseB();
        }
        if (self::$phaseCDone) {
            return;
        }

        // 1. Ensure plugin directory constants and global paths exist
        if (!isset($GLOBALS['wp_plugin_paths']) || !is_array($GLOBALS['wp_plugin_paths'])) {
            $GLOBALS['wp_plugin_paths'] = [];
        }
        if (function_exists('wp_plugin_directory_constants') && !defined('WP_PLUGIN_DIR')) {
            wp_plugin_directory_constants();
        }

        // 2. Evidence bridge must be online before GeDefense emits runtime events.
        SecurityEventBridge::init();

        // 3. Full GeDefense Kernel Engagement (Phase 1 pre-flight & Phase 2 hardening)
        if (is_dir(dirname(__DIR__) . '/GeDefense')) {
            GeDefenseKernel::engageEarly();
            GeDefenseKernel::engageFull();
        }

        // 4. Database Schema Migrations (Astraea Core)
        global $wpdb;
        if (isset($wpdb) && ($wpdb instanceof \wpdb) && !empty($wpdb->dbh) && function_exists('get_option')) {
            if (get_option('astraea_db_version') !== MigrationRunner::ASTRAEA_DB_VERSION) {
                $runner = new MigrationRunner();
                $runner->runPending([
                    new Migration_001_InitialSchema(),
                    new Migration_002_VLPLight(),
                    new Migration_003_OperationalHardening(),
                ]);
            }
        }

        // 5. Execute Phase C Modules
        ModuleBootstrap::bootPhase(BootPhase::PHASE_C);

        self::$phaseCDone = true;
    }

    /**
     * Phase D: Pre-Plugin Recovery Gate.
     * Executes IMMEDIATELY BEFORE any MU, network, or regular plugin is loaded.
     * Astraea Vault evaluates pending recoveries and quarantines broken updates.
     */
    public static function bootPhaseD(): void {
        if (!self::$phaseCDone) {
            self::bootPhaseC();
        }
        if (self::$phaseDDone) {
            return;
        }

        // Engage Astraea Vault Pre-Plugin Recovery Gate (Core-Native)
        if (!defined('ASTRAEA_VAULT_CORE_NATIVE')) {
            define('ASTRAEA_VAULT_CORE_NATIVE', true);
        }
        $vaultCoreDir = dirname(__DIR__) . '/Vault';
        if (!defined('ASTRAEA_VAULT_DIR')) {
            define('ASTRAEA_VAULT_DIR', $vaultCoreDir);
        }
        $vaultEarly = $vaultCoreDir . '/integration/astraea-core-early-bootstrap.php';
        if (file_exists($vaultEarly)) {
            require_once $vaultEarly;
        }

        // Execute Phase D Modules
        ModuleBootstrap::bootPhase(BootPhase::PHASE_D);

        self::$phaseDDone = true;
    }

    /**
     * Phase E: Normal Astraea Post-Bootstrap & Admin UI.
     * Hooks into standard WordPress lifecycle actions (plugins_loaded, init, admin_init).
     */
    public static function bootPhaseE(): void {
        if (!self::$phaseDDone) {
            self::bootPhaseD();
        }
        if (self::$phaseEDone) {
            return;
        }

        // Engage Astraea Vault Full Services (Core-Native)
        if (class_exists('\\Astraea\\Vault\\Plugin')) {
            \Astraea\Vault\Plugin::instance()->boot();
        }

        // Session-bound privileged re-authentication and interactive mutation guard.
        StepUpAuthService::init();
        PrivilegedActionGuard::init();

        // Core Update Shield (suppresses upstream WordPress overwrites)
        CoreUpdateGuard::init();

        // Module Fabric Admin Interface
        ModuleAdmin::init();

        // VLP Light — kernel-native consent, DOM gatekeeper, scanner and Dattrack.
        VLPLightKernel::boot();

        // Astraea Mail Gateway — encrypted first-party SMTP transport.
        MailKernel::boot();

        // Incomplete Secure Genesis transactions are resumed before normal admin work.
        SecureGenesis::initIncompleteInstallGuard();

        // Admin UI Bootstrap (Glass Shell, Categorized Menus, Command Palette)
        AdminUIBootstrap::boot();

        // WP-CLI Command Suite
        if (defined('WP_CLI') && WP_CLI && class_exists('\\WP_CLI')) {
            require_once dirname(__DIR__) . '/CLI/AstraeaCommand.php';
        }

        // Execute Phase E First-Party Modules via Module Fabric
        ModuleBootstrap::bootPhase(BootPhase::PHASE_E);

        // Mark deterministic boot as completed normally
        BootFailureDetector::markBootSuccessful();

        self::$phaseEDone = true;
    }

    /**
     * Pure PHP In-Memory / File-based Perimeter Check.
     * Runs with zero WordPress function dependencies.
     *
     * Enforces strict passive JSON parsing — NEVER executes dynamic PHP includes
     * from upload directories.
     */
    private static function checkPerimeterNative(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return;
        }

        // Check for local perimeter drop file if present (strictly JSON, zero PHP execution)
        $perimeterDropJson = dirname(__DIR__, 2) . '/wp-content/uploads/vgt-temp/cerberus_banned_ips.json';
        if (is_file($perimeterDropJson)) {
            $raw = @file_get_contents($perimeterDropJson);
            if (is_string($raw) && strlen($raw) < 1048576) {
                try {
                    $banned = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($banned) && (isset($banned[$ip]) || in_array($ip, $banned, true))) {
                        http_response_code(403);
                        header('Content-Type: text/plain; charset=utf-8');
                        header('X-Astraea-Drop: Cerberus-L0');
                        exit('Access Denied (Astraea Perimeter Defense)');
                    }
                } catch (\Throwable) {
                    // Corrupted drop file handled safely without crashing boot
                }
            }
        }
    }
}
