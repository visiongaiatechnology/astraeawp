<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Recovery;

use Astraea\Vault\Config;
use Astraea\Vault\Crypto\CryptoService;
use Astraea\Vault\Crypto\KeyManager;
use Astraea\Vault\Notification\Notifier;
use Astraea\Vault\Integration\AstraeaCore;
use Astraea\Vault\Repository\IncidentRepository;
use Astraea\Vault\Restore\RestoreService;

final class RecoveryManager
{
    private bool $ran = false;

    public function __construct(
        private readonly KeyManager $keys,
        private readonly CryptoService $crypto,
        private readonly RestoreService $restore,
        private readonly IncidentRepository $incidents,
        private readonly Notifier $notifier
    ) {}

    public function maybeRecover(): void
    {
        if ($this->ran) { return; }
        $this->ran = true;
        $pending = get_option(Config::OPTION_RECOVERY, null);
        if (!is_array($pending)) { return; }
        $settings = Config::settings();
        if (empty($settings['auto_rollback'])) { return; }
        $plugin = (string)($pending['plugin'] ?? '');
        $backup = (string)($pending['backup_id'] ?? '');
        if ($plugin === '' || $backup === '') { return; }

        $master = '';
        $result = 'failed';
        try {
            $master = $this->keys->unlockWithServiceKey();
            $this->restore->restorePluginSnapshot($backup, $master, $plugin);
            $result = 'rolled_back';
            $this->updateTransaction($plugin, ['status' => 'rolled_back', 'rolled_back_at' => time()]);
            delete_option(Config::OPTION_RECOVERY);
            $blocked = get_option('astraea_vault_blocked_updates', []);
            if (!is_array($blocked)) { $blocked = []; }
            $blocked[$plugin] = [
                'version' => (string)($pending['new_version'] ?? ''),
                'at' => time(),
                'reason' => 'fatal-after-update',
            ];
            update_option('astraea_vault_blocked_updates', $blocked, false);
            AstraeaCore::securityEvent('automatic_plugin_rollback', [
                'plugin' => $plugin,
                'backup_id' => $backup,
                'update_tx' => (string)($pending['id'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $this->quarantine($plugin);
            $result = 'quarantined';
            delete_option(Config::OPTION_RECOVERY);
            AstraeaCore::securityEvent('automatic_recovery_failed', [
                'plugin' => $plugin,
                'backup_id' => $backup,
                'update_tx' => (string)($pending['id'] ?? ''),
            ]);
            try {
                $this->incidents->record([
                    'severity' => 'critical',
                    'component' => 'recovery',
                    'error_type' => get_class($e),
                    'error_message' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'plugin' => $plugin,
                    'backup_id' => $backup,
                    'update_tx' => (string)($pending['id'] ?? ''),
                    'recovery_action' => 'quarantine_after_failed_rollback',
                    'recovery_result' => 'quarantined',
                ]);
            } catch (\Throwable) {}
            $this->updateTransaction($plugin, ['status' => 'recovery_failed', 'recovery_failed_at' => time()]);
        } finally {
            if ($master !== '') { $this->crypto->wipe($master); }
        }

        $pending['recovery_result'] = $result;
        $this->notifier->recovery($pending);
    }

    private function updateTransaction(string $plugin, array $changes): void
    {
        $raw = get_option(Config::OPTION_UPDATE_TX, []);
        if (!is_array($raw)) { $raw = []; }
        if (isset($raw['id'], $raw['plugin'])) {
            $legacyPlugin = (string)$raw['plugin'];
            $raw = $legacyPlugin !== '' ? [$legacyPlugin => $raw] : [];
        }
        $tx = isset($raw[$plugin]) && is_array($raw[$plugin]) ? $raw[$plugin] : ['plugin' => $plugin];
        $raw[$plugin] = array_merge($tx, $changes);
        update_option(Config::OPTION_UPDATE_TX, $raw, false);
    }

    private function quarantine(string $plugin): void
    {
        $removed = false;

        $active = get_option('active_plugins', []);
        if (is_array($active)) {
            $filtered = array_values(array_filter(
                $active,
                static fn($value): bool => !is_string($value) || !hash_equals($plugin, $value)
            ));
            if ($filtered !== $active) {
                update_option('active_plugins', $filtered, false);
                $removed = true;
            }
        }

        if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option') && function_exists('update_site_option')) {
            $networkActive = get_site_option('active_sitewide_plugins', []);
            if (is_array($networkActive) && array_key_exists($plugin, $networkActive)) {
                unset($networkActive[$plugin]);
                update_site_option('active_sitewide_plugins', $networkActive);
                $removed = true;
            }
        }

        if (!$removed) {
            AstraeaCore::securityEvent('plugin_quarantine_not_applicable', [
                'plugin' => $plugin,
                'reason' => 'plugin-not-found-in-site-or-network-activation-state',
            ]);
        }
    }
}
