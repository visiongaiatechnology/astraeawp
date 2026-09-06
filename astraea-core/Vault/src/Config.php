<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault;

final class Config
{
    public const OPTION_SETTINGS = 'astraea_vault_settings';
    public const OPTION_KEYRING = 'astraea_vault_keyring';
    public const OPTION_UPDATE_TX = 'astraea_vault_update_tx';
    public const OPTION_RECOVERY = 'astraea_vault_recovery_pending';
    public const CRON_HOOK = 'astraea_vault_backup_event';

    public static function settings(): array
    {
        $defaults = [
            'schedule' => 'daily',
            'scheduled_type' => 'full',
            'retention' => 14,
            'update_guard' => true,
            'strict_update_guard' => true,
            'auto_rollback' => true,
            'email_notifications' => true,
        ];
        $value = get_option(self::OPTION_SETTINGS, []);
        return array_merge($defaults, is_array($value) ? $value : []);
    }

    public static function storageRoot(): string
    {
        if (defined('ASTRAEA_VAULT_STORAGE_PATH') && is_string(ASTRAEA_VAULT_STORAGE_PATH) && ASTRAEA_VAULT_STORAGE_PATH !== '') {
            return rtrim(ASTRAEA_VAULT_STORAGE_PATH, '/\\');
        }

        $openBasedir = (string) ini_get('open_basedir');
        if ($openBasedir === '') {
            $parent = dirname(rtrim(ABSPATH, '/\\'));
            if (@is_dir($parent) && @is_writable($parent)) {
                return $parent . DIRECTORY_SEPARATOR . '.astraea-vault';
            }
        }

        return rtrim(WP_CONTENT_DIR, '/\\') . DIRECTORY_SEPARATOR . '.astraea-vault';
    }
}
