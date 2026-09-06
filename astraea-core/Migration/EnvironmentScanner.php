<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Migration;

/**
 * Diagnostic environment and compatibility scanner for WordPress -> Astraea migration.
 *
 * @package Astraea\Migration
 */
final class EnvironmentScanner {

    /**
     * Scan host environment, active plugins, theme, and filesystem readiness.
     *
     * @return array<string, mixed>
     */
    public static function scan(): array {
        global $wp_version, $wpdb;

        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.2.0', '>=');

        $dbVersion = 'unknown';
        $dbOk = false;
        if (isset($wpdb) && $wpdb instanceof \wpdb && !empty($wpdb->dbh)) {
            $dbVersion = (string)$wpdb->db_version();
            $cleanVer = preg_replace('/[^0-9.]/', '', $dbVersion) ?? '';
            $dbOk = version_compare($cleanVer, '8.0.0', '>=') || version_compare($cleanVer, '10.5.0', '>=');
        }

        $extensions = [
            'sodium'  => extension_loaded('sodium'),
            'gd'      => extension_loaded('gd'),
            'imagick' => extension_loaded('imagick'),
            'openssl' => extension_loaded('openssl'),
            'json'    => extension_loaded('json'),
            'mysqli'  => extension_loaded('mysqli'),
            'curl'    => extension_loaded('curl'),
        ];

        $activePlugins = function_exists('get_option') ? (array)get_option('active_plugins', []) : [];
        $activeTheme = function_exists('wp_get_theme') ? wp_get_theme()->get('Name') : 'Unknown';

        return [
            'wp_version'      => $wp_version ?? 'unknown',
            'php_version'     => $phpVersion,
            'php_ok'          => $phpOk,
            'db_version'      => $dbVersion,
            'db_ok'           => $dbOk,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI/Unknown',
            'memory_limit'    => ini_get('memory_limit') ?: 'unknown',
            'extensions'      => $extensions,
            'active_plugins'  => $activePlugins,
            'active_theme'    => $activeTheme,
            'is_ssl'          => is_ssl(),
            'timestamp'       => time(),
        ];
    }
}
