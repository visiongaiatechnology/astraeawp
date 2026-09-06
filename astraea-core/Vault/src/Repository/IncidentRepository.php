<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Repository;

use Astraea\Vault\Exception\StorageException;
use Astraea\Vault\Support\Uuid;

final class IncidentRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $prefix = isset($wpdb->prefix) ? (string)$wpdb->prefix : 'wp_';
        $this->table = $prefix . 'astraea_vault_incidents';
    }

    public function record(array $incident): string
    {
        global $wpdb;
        $id = Uuid::v4();
        $ok = $wpdb->insert($this->table, [
            'id' => $id,
            'severity' => (string)($incident['severity'] ?? 'high'),
            'component' => substr((string)($incident['component'] ?? 'unknown'), 0, 191),
            'error_type' => substr((string)($incident['error_type'] ?? 'unknown'), 0, 64),
            'error_message' => $this->sanitizeMessage((string)($incident['error_message'] ?? 'Unknown error')),
            'error_file' => $this->sanitizePath((string)($incident['error_file'] ?? '')),
            'error_line' => max(0, (int)($incident['error_line'] ?? 0)),
            'plugin' => substr((string)($incident['plugin'] ?? ''), 0, 191),
            'backup_id' => substr((string)($incident['backup_id'] ?? ''), 0, 36),
            'update_tx' => substr((string)($incident['update_tx'] ?? ''), 0, 36),
            'recovery_action' => substr((string)($incident['recovery_action'] ?? ''), 0, 64),
            'recovery_result' => substr((string)($incident['recovery_result'] ?? ''), 0, 32),
            'created_at' => current_time('mysql', true),
        ], ['%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s']);
        if ($ok === false) {
            throw new StorageException('Unable to persist recovery incident.');
        }
        return $id;
    }

    public function recent(int $limit = 25): array
    {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $sql = $wpdb->prepare("SELECT * FROM `{$this->table}` ORDER BY created_at DESC LIMIT %d", $limit);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/(authorization|cookie|password|token|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $message) ?? $message;
        return substr(wp_strip_all_tags($message), 0, 4000);
    }

    private function sanitizePath(string $path): string
    {
        $normalized = wp_normalize_path($path);
        $root = wp_normalize_path(ABSPATH);
        if (str_starts_with($normalized, $root)) {
            return '[ABSPATH]/' . ltrim(substr($normalized, strlen($root)), '/');
        }
        return basename($normalized);
    }
}
