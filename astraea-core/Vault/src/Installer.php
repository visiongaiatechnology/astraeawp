<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault;

use Astraea\Vault\Storage\LocalStorage;
use Astraea\Vault\Exception\SecurityException;
use Astraea\Vault\Exception\StorageException;

final class Installer
{
    public const SCHEMA_VERSION = 1;
    private const SCHEMA_OPTION = 'astraea_vault_schema_version';

    public static function activate(): void
    {
        try {
            self::ensureCoreInstalled();
            self::ensureCapabilities();
            Scheduler\Scheduler::reschedule();
        } catch (\Throwable $e) {
            if (function_exists('deactivate_plugins') && defined('ASTRAEA_VAULT_FILE')) {
                deactivate_plugins(plugin_basename(ASTRAEA_VAULT_FILE));
            }
            wp_die(esc_html__('Astraea Vault could not initialize its secure storage.', 'astraea-vault'));
        }
    }

    /**
     * Idempotent core-native installation path. Safe in Phase D after database
     * and options are available, before third-party plugins are loaded.
     */
    public static function ensureCoreInstalled(): void
    {
        self::assertRuntime();
        if ((int)get_option(self::SCHEMA_OPTION, 0) !== self::SCHEMA_VERSION) {
            self::installTables();
            update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        }
        (new LocalStorage())->initialize();
    }

    /**
     * Role capabilities are applied separately because roles are a WordPress
     * lifecycle concern and must not be required by the pre-plugin recovery gate.
     */
    public static function ensureCapabilities(): void
    {
        if (!function_exists('get_role')) {
            return;
        }
        $role = get_role('administrator');
        if ($role === null) {
            return;
        }
        foreach (['manage_astraea_vault', 'restore_astraea_vault', 'download_astraea_vault'] as $cap) {
            if (!$role->has_cap($cap)) {
                $role->add_cap($cap, true);
            }
        }
    }

    public static function deactivate(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(Config::CRON_HOOK);
        }
    }

    private static function assertRuntime(): void
    {
        if (PHP_VERSION_ID < 80300 || !extension_loaded('openssl') || !extension_loaded('sodium')) {
            throw new SecurityException('Vault runtime prerequisites are unavailable.');
        }
        if (!in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new SecurityException('AES-256-GCM is unavailable in this OpenSSL runtime.');
        }
    }

    private static function installTables(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof \wpdb)) {
            throw new SecurityException('Vault schema installation requires an initialized database connection.');
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $backups = $wpdb->prefix . 'astraea_vault_backups';
        $incidents = $wpdb->prefix . 'astraea_vault_incidents';

        dbDelta("CREATE TABLE {$backups} (
            id char(36) NOT NULL,
            type varchar(32) NOT NULL,
            status varchar(32) NOT NULL,
            filename varchar(255) NOT NULL,
            size_bytes bigint unsigned NOT NULL DEFAULT 0,
            sha256 char(64) NOT NULL DEFAULT '',
            protected tinyint(1) NOT NULL DEFAULT 0,
            trigger_source varchar(64) NOT NULL DEFAULT 'manual',
            meta longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY status_created (status, created_at),
            KEY type_created (type, created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$incidents} (
            id char(36) NOT NULL,
            severity varchar(16) NOT NULL,
            component varchar(191) NOT NULL,
            error_type varchar(64) NOT NULL,
            error_message text NOT NULL,
            error_file text NOT NULL,
            error_line int unsigned NOT NULL DEFAULT 0,
            plugin varchar(191) NOT NULL DEFAULT '',
            backup_id char(36) NOT NULL DEFAULT '',
            update_tx char(36) NOT NULL DEFAULT '',
            recovery_action varchar(64) NOT NULL DEFAULT '',
            recovery_result varchar(32) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY plugin_created (plugin, created_at)
        ) {$charset};");

        foreach ([$backups, $incidents] as $requiredTable) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $requiredTable));
            if (!is_string($found) || !hash_equals($requiredTable, $found)) {
                throw new StorageException('Vault database schema verification failed.');
            }
        }
    }
}
