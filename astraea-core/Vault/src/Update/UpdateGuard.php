<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Update;

use Astraea\Vault\Backup\BackupService;
use Astraea\Vault\Config;
use Astraea\Vault\Crypto\CryptoService;
use Astraea\Vault\Crypto\KeyManager;
use Astraea\Vault\Notification\Notifier;
use Astraea\Vault\Integration\AstraeaCore;
use Astraea\Vault\Support\Uuid;

final class UpdateGuard
{
    private static bool $registered = false;

    public function __construct(
        private readonly BackupService $backups,
        private readonly KeyManager $keys,
        private readonly CryptoService $crypto,
        private readonly Notifier $notifier
    ) {}

    public function register(): void
    {
        if (self::$registered) { return; }
        self::$registered = true;
        add_filter('upgrader_pre_install', [$this, 'beforeInstall'], 1, 2);
        add_action('upgrader_process_complete', [$this, 'afterInstall'], 20, 2);
        add_action('wp_loaded', [$this, 'commitHealthy'], PHP_INT_MAX);
        add_filter('auto_update_plugin', [$this, 'blockFailedAutoUpdate'], 10, 2);
    }

    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    public function beforeInstall($response, array $hookExtra)
    {
        if (is_wp_error($response)) { return $response; }
        $settings = Config::settings();
        if (empty($settings['update_guard']) || ($hookExtra['action'] ?? '') !== 'update' || ($hookExtra['type'] ?? '') !== 'plugin') {
            return $response;
        }
        $plugin = $this->extractPlugin($hookExtra);
        if ($plugin === null) { return $response; }

        $master = '';
        try {
            $previous = $this->pluginVersion($plugin);
            $txId = Uuid::v4();
            $master = $this->keys->unlockWithServiceKey();
            $snapshot = $this->backups->create('plugin', $master, 'update_guard', [
                'plugin' => $plugin,
                'previous_version' => $previous,
                'transaction_id' => $txId,
            ]);
            $map = $this->transactions();
            $map[$plugin] = [
                'id' => $txId,
                'plugin' => $plugin,
                'backup_id' => (string)$snapshot['id'],
                'previous_version' => $previous,
                'new_version' => '',
                'status' => 'updating',
                'started_at' => time(),
            ];
            $this->saveTransactions($map);
            return $response;
        } catch (\Throwable $e) {
            AstraeaCore::log('error', 'Pre-update snapshot failed.', ['exception' => get_class($e), 'plugin' => $plugin]);
            if (!empty($settings['strict_update_guard'])) {
                return new \WP_Error('astraea_vault_snapshot_failed', __('Astraea Vault blocked the update because the verified pre-update snapshot could not be created.', 'astraea-vault'));
            }
            $this->notifier->info('Update Guard warning', 'The pre-update snapshot failed; strict blocking is disabled.', 'warning');
            return $response;
        } finally {
            if ($master !== '') { $this->crypto->wipe($master); }
        }
    }

    public function afterInstall($upgrader, array $hookExtra): void
    {
        if (($hookExtra['action'] ?? '') !== 'update' || ($hookExtra['type'] ?? '') !== 'plugin') { return; }
        $plugins = $this->extractPlugins($hookExtra);
        if ($plugins === []) { return; }
        $map = $this->transactions();
        $changed = false;
        foreach ($plugins as $plugin) {
            if (!isset($map[$plugin]) || (string)($map[$plugin]['status'] ?? '') !== 'updating') { continue; }
            $map[$plugin]['new_version'] = $this->pluginVersion($plugin);
            $map[$plugin]['status'] = 'awaiting_healthcheck';
            $map[$plugin]['updated_at'] = time();
            $changed = true;
        }
        if ($changed) { $this->saveTransactions($map); }
    }

    public function commitHealthy(): void
    {
        if (get_option(Config::OPTION_RECOVERY, null) !== null) { return; }
        $map = $this->transactions();
        $changed = false;
        foreach ($map as $plugin => $tx) {
            if ((string)($tx['status'] ?? '') !== 'awaiting_healthcheck') { continue; }
            $updatedAt = (int)($tx['updated_at'] ?? 0);
            if ($updatedAt <= 0 || $updatedAt >= time()) { continue; }
            $map[$plugin]['status'] = 'committed';
            $map[$plugin]['committed_at'] = time();
            $changed = true;
        }
        if ($changed) { $this->saveTransactions($map); }
    }

    public function blockFailedAutoUpdate($update, $item)
    {
        if ($update === false || !is_object($item)) { return $update; }
        $plugin = isset($item->plugin) && is_string($item->plugin) ? wp_normalize_path($item->plugin) : '';
        if ($plugin === '') { return $update; }
        $blocked = get_option('astraea_vault_blocked_updates', []);
        if (!is_array($blocked) || !isset($blocked[$plugin])) { return $update; }
        $failedVersion = (string)($blocked[$plugin]['version'] ?? '');
        $candidate = isset($item->new_version) && is_string($item->new_version) ? $item->new_version : '';
        return ($failedVersion !== '' && $candidate !== '' && version_compare($candidate, $failedVersion, '>')) ? $update : false;
    }

    private function transactions(): array
    {
        $raw = get_option(Config::OPTION_UPDATE_TX, []);
        if (!is_array($raw)) { return []; }
        if (isset($raw['id'], $raw['plugin'])) {
            $plugin = (string)$raw['plugin'];
            return $plugin !== '' ? [$plugin => $raw] : [];
        }
        $result = [];
        foreach ($raw as $plugin => $tx) {
            if (!is_string($plugin) || !is_array($tx) || ($tx['plugin'] ?? '') !== $plugin) { continue; }
            $result[$plugin] = $tx;
        }
        return $result;
    }

    private function saveTransactions(array $map): void
    {
        if (count($map) > 100) {
            uasort($map, static fn(array $a, array $b): int => (int)($b['started_at'] ?? 0) <=> (int)($a['started_at'] ?? 0));
            $map = array_slice($map, 0, 100, true);
        }
        update_option(Config::OPTION_UPDATE_TX, $map, false);
    }

    private function extractPlugins(array $hookExtra): array
    {
        $result = [];
        $single = $hookExtra['plugin'] ?? null;
        if (is_string($single) && $single !== '') { $result[] = $single; }
        $many = $hookExtra['plugins'] ?? null;
        if (is_array($many)) {
            foreach ($many as $plugin) {
                if (is_string($plugin) && $plugin !== '') { $result[] = $plugin; }
            }
        }
        $clean = [];
        foreach (array_unique($result) as $plugin) {
            if (str_contains($plugin, '..') || str_contains($plugin, "\0")) { continue; }
            $clean[] = wp_normalize_path($plugin);
        }
        return $clean;
    }

    private function extractPlugin(array $hookExtra): ?string
    {
        $plugins = $this->extractPlugins($hookExtra);
        return count($plugins) === 1 ? $plugins[0] : null;
    }

    private function pluginVersion(string $plugin): string
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $path = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $plugin);
        if (!is_file($path)) { return 'unknown'; }
        $data = get_plugin_data($path, false, false);
        return isset($data['Version']) && is_string($data['Version']) ? $data['Version'] : 'unknown';
    }
}
