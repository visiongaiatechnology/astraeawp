<?php
// STATUS: DIAMANT VGT SUPREME
/**
 * Plugin Name: Astraea Vault
 * Plugin URI: https://visiongaiatechnology.de/
 * Description: Encrypted backup, transactional update protection and autonomous recovery engine for AstraeaOS WP.
 * Version: 0.3.0-alpha
 * Author: VisionGaiaTechnology
 * License: AGPL-3.0-or-later
 * Requires PHP: 8.3
 * Requires at least: 6.8
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/version.php';
if (!defined('ASTRAEA_VAULT_FILE')) {
    define('ASTRAEA_VAULT_FILE', __FILE__);
}
if (!defined('ASTRAEA_VAULT_DIR')) {
    define('ASTRAEA_VAULT_DIR', __DIR__);
}
if (!defined('ASTRAEA_VAULT_URL') || ASTRAEA_VAULT_URL === '') {
    if (!defined('ASTRAEA_VAULT_URL')) {
        define('ASTRAEA_VAULT_URL', function_exists('plugin_dir_url') ? plugin_dir_url(__FILE__) : '');
    }
}

require_once ASTRAEA_VAULT_DIR . '/src/Autoloader.php';
\Astraea\Vault\Autoloader::register(ASTRAEA_VAULT_DIR . '/src');

register_activation_hook(__FILE__, [\Astraea\Vault\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [\Astraea\Vault\Installer::class, 'deactivate']);

\Astraea\Vault\Plugin::instance()->boot();
