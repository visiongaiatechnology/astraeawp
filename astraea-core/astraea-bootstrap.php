<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

/**
 * AstraeaOS WP — Core Ignition & Bootstrap.
 *
 * Product: AstraeaOS WP
 * Ecosystem: AstraeaOS / VisionGaiaTechnology
 * Tagline: A modern, hardened, and lightweight WordPress distribution without decades of unnecessary legacy baggage.
 *
 * Deterministic 5-Phase Boot Sequence:
 * - Phase A: Pre-WordPress Minimal (Native PHP only, zero WP APIs)
 * - Phase B: WordPress Core Ready (Post functions.php & formatting.php)
 * - Phase C: Database & Options Ready (Post $wpdb & options)
 * - Phase D: Pre-Plugin Recovery Gate (Astraea Vault autonomous rollback)
 * - Phase E: Normal Astraea Lifecycle (AdminUI, Control Center, Tools)
 *
 * @package Astraea
 */

if (!defined('ABSPATH')) {
    exit('Access Denied');
}

// 1. Core Fork Versioning — single source of truth.
require_once __DIR__ . '/Version.php';
if (!defined('ASTRAEA_VERSION')) {
    define('ASTRAEA_VERSION', \Astraea\Version::VERSION);
}
if (!defined('ASTRAEA_CORE_DIR')) {
    define('ASTRAEA_CORE_DIR', __DIR__ . '/');
}

// 2. Register Autoloader
require_once __DIR__ . '/Bootstrap/Autoloader.php';
\Astraea\Bootstrap\Autoloader::register(__DIR__);

// 3. Execute Phase A: Native runtime assertion & Keyring ignition (Zero WP APIs)
\Astraea\Bootstrap\BootOrchestrator::bootPhaseA();

// 4. Hook Phase E into plugins_loaded (Priority 5, before normal plugins init)
if (function_exists('add_action')) {
    add_action('plugins_loaded', static function(): void {
        \Astraea\Bootstrap\BootOrchestrator::bootPhaseE();
    }, 5);
}
