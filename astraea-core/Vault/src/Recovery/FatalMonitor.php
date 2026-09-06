<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Recovery;

use Astraea\Vault\Config;
use Astraea\Vault\Repository\IncidentRepository;

final class FatalMonitor
{
    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    private bool $registered = false;

    public function __construct(private readonly IncidentRepository $incidents) {}

    public function register(): void
    {
        if ($this->registered) { return; }
        $this->registered = true;
        register_shutdown_function([$this, 'capture']);
    }

    public function capture(): void
    {
        $error = error_get_last();
        if (!is_array($error) || !in_array((int)($error['type'] ?? 0), self::FATAL_TYPES, true)) { return; }
        $file = (string)($error['file'] ?? '');
        $tx = $this->matchingTransaction($file);
        if ($tx === null) { return; }

        $plugin = (string)$tx['plugin'];
        $sanitizedFile = $this->sanitizePath($file);
        $incident = [
            'severity' => 'critical',
            'component' => 'plugin-update',
            'error_type' => $this->typeName((int)$error['type']),
            'error_message' => (string)($error['message'] ?? 'Fatal plugin error'),
            'error_file' => $sanitizedFile,
            'error_line' => (int)($error['line'] ?? 0),
            'plugin' => $plugin,
            'backup_id' => (string)($tx['backup_id'] ?? ''),
            'update_tx' => (string)($tx['id'] ?? ''),
            'recovery_action' => 'automatic_plugin_rollback',
            'recovery_result' => 'pending',
        ];
        try {
            $incidentId = $this->incidents->record($incident);
        } catch (\Throwable) {
            $incidentId = '';
        }
        $pending = $tx;
        $pending['incident_id'] = $incidentId;
        $pending['error_type'] = $incident['error_type'];
        $pending['error_message'] = $this->sanitizeMessage((string)$incident['error_message']);
        $pending['error_file'] = $sanitizedFile;
        $pending['error_line'] = $incident['error_line'];
        $pending['detected_at'] = time();
        update_option(Config::OPTION_RECOVERY, $pending, false);
    }

    private function matchingTransaction(string $errorFile): ?array
    {
        $raw = get_option(Config::OPTION_UPDATE_TX, []);
        if (!is_array($raw)) { return null; }
        $transactions = isset($raw['id'], $raw['plugin']) ? [(string)$raw['plugin'] => $raw] : $raw;
        $matches = [];
        foreach ($transactions as $tx) {
            if (!is_array($tx) || !in_array((string)($tx['status'] ?? ''), ['updating', 'awaiting_healthcheck', 'committed'], true)) { continue; }
            $started = (int)($tx['started_at'] ?? 0);
            $window = (string)($tx['status'] ?? '') === 'committed' ? 900 : 1800;
            if ($started < time() - $window || $started > time() + 60) { continue; }
            $plugin = (string)($tx['plugin'] ?? '');
            if ($this->correlates($plugin, $errorFile)) { $matches[] = $tx; }
        }
        if ($matches === []) { return null; }
        usort($matches, static fn(array $a, array $b): int => (int)($b['started_at'] ?? 0) <=> (int)($a['started_at'] ?? 0));
        return $matches[0];
    }

    private function correlates(string $plugin, string $errorFile): bool
    {
        if ($plugin === '' || $errorFile === '') { return false; }
        $plugin = wp_normalize_path($plugin);
        $error = wp_normalize_path($errorFile);
        $root = wp_normalize_path(WP_PLUGIN_DIR) . '/';
        if (dirname($plugin) === '.') {
            return hash_equals($root . basename($plugin), $error);
        }
        return str_starts_with($error, $root . trim(dirname($plugin), '/') . '/');
    }

    private function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/(authorization|cookie|password|token|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $message) ?? $message;
        return substr(wp_strip_all_tags($message), 0, 2000);
    }

    private function sanitizePath(string $path): string
    {
        $normalized = wp_normalize_path($path);
        $root = wp_normalize_path(ABSPATH);
        return str_starts_with($normalized, $root) ? '[ABSPATH]/' . ltrim(substr($normalized, strlen($root)), '/') : basename($normalized);
    }

    private function typeName(int $type): string
    {
        return match ($type) {
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_USER_ERROR => 'E_USER_ERROR',
            default => 'FATAL',
        };
    }
}
