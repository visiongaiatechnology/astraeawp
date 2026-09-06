<?php
// STATUS: DIAMANT VGT SUPREME
/**
 * AstraeaOS WP pre-plugin recovery integration.
 *
 * Core-native load point for AstraeaOS WP 0.3.0-alpha / WordPress 7.1:
 * after DB, object cache, multisite bootstrap and plugin directory constants,
 * immediately BEFORE must-use, network-active and normal active plugins load.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ASTRAEA_VAULT_CORE_NATIVE') || ASTRAEA_VAULT_CORE_NATIVE !== true) {
    return;
}

$vaultDir = defined('ASTRAEA_VAULT_DIR') ? ASTRAEA_VAULT_DIR : dirname(__DIR__);
if (!is_string($vaultDir) || $vaultDir === '' || !is_file($vaultDir . '/src/Autoloader.php')) {
    return;
}

require_once $vaultDir . '/version.php';
if (!defined('ASTRAEA_VAULT_FILE')) {
    define('ASTRAEA_VAULT_FILE', $vaultDir . '/astraea-vault.php');
}
if (!defined('ASTRAEA_VAULT_DIR')) {
    define('ASTRAEA_VAULT_DIR', $vaultDir);
}
if (!defined('ASTRAEA_VAULT_URL')) {
    $vaultUrl = function_exists('site_url') ? (function_exists('trailingslashit') ? trailingslashit(site_url('/astraea-core/Vault')) : rtrim(site_url('/astraea-core/Vault'), '/\\') . '/') : '';
    define('ASTRAEA_VAULT_URL', $vaultUrl);
}

require_once ASTRAEA_VAULT_DIR . '/src/Autoloader.php';
\Astraea\Vault\Autoloader::register(ASTRAEA_VAULT_DIR . '/src');
try {
    \Astraea\Vault\Installer::ensureCoreInstalled();
    \Astraea\Vault\Plugin::instance()->bootEarlyRecovery();
} catch (\Throwable $e) {
    if (!defined('ASTRAEA_VAULT_BOOT_FAILED')) {
        define('ASTRAEA_VAULT_BOOT_FAILED', true);
    }
    if (class_exists('\\Astraea\\Security\\SecurityEventManager')) {
        \Astraea\Security\SecurityEventManager::recordOnce(
            \Astraea\Security\SecurityEventManager::SEVERITY_CRITICAL,
            'Vault',
            'core_native_boot_failed',
            'Astraea Vault core-native initialization failed. Recovery protection is unavailable until repaired.',
            ['exception' => get_class($e)],
            300
        );
    }
    if (class_exists('\\Astraea\\Security\\Logger')) {
        \Astraea\Security\Logger::critical('[Vault] Core-native initialization failed.', ['exception' => get_class($e)]);
    }
}
