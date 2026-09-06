<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Repository;

use Astraea\Vault\Exception\StorageException;

final class BackupRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $prefix = isset($wpdb->prefix) ? (string)$wpdb->prefix : 'wp_';
        $this->table = $prefix . 'astraea_vault_backups';
    }

    public function create(array $record): void
    {
        global $wpdb;
        $ok = $wpdb->insert(
            $this->table,
            [
                'id' => (string)$record['id'],
                'type' => (string)$record['type'],
                'status' => (string)$record['status'],
                'filename' => (string)$record['filename'],
                'size_bytes' => (int)($record['size_bytes'] ?? 0),
                'sha256' => (string)($record['sha256'] ?? ''),
                'protected' => !empty($record['protected']) ? 1 : 0,
                'trigger_source' => (string)($record['trigger_source'] ?? 'manual'),
                'meta' => wp_json_encode($record['meta'] ?? [], JSON_UNESCAPED_SLASHES),
                'created_at' => current_time('mysql', true),
            ],
            ['%s','%s','%s','%s','%d','%s','%d','%s','%s','%s']
        );
        if ($ok === false) {
            throw new StorageException('Unable to persist backup metadata.');
        }
    }

    public function updateStatus(string $id, string $status, int $size = 0, string $sha256 = '', array $meta = []): void
    {
        global $wpdb;
        $data = ['status' => $status];
        $formats = ['%s'];
        if ($size > 0) { $data['size_bytes'] = $size; $formats[] = '%d'; }
        if ($sha256 !== '') { $data['sha256'] = $sha256; $formats[] = '%s'; }
        if ($meta !== []) { $data['meta'] = wp_json_encode($meta, JSON_UNESCAPED_SLASHES); $formats[] = '%s'; }
        $result = $wpdb->update($this->table, $data, ['id' => $id], $formats, ['%s']);
        if ($result === false) {
            throw new StorageException('Unable to update backup metadata.');
        }
    }

    public function find(string $id): ?array
    {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM `{$this->table}` WHERE id = %s LIMIT 1", $id);
        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $this->normalize($row) : null;
    }

    public function all(int $limit = 100): array
    {
        global $wpdb;
        $limit = max(1, min(500, $limit));
        $sql = $wpdb->prepare("SELECT * FROM `{$this->table}` ORDER BY created_at DESC LIMIT %d", $limit);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return array_map(fn(array $row): array => $this->normalize($row), is_array($rows) ? $rows : []);
    }

    public function delete(string $id): void
    {
        global $wpdb;
        $result = $wpdb->delete($this->table, ['id' => $id], ['%s']);
        if ($result === false) {
            throw new StorageException('Unable to delete backup metadata.');
        }
    }

    public function retentionCandidates(int $keep): array
    {
        global $wpdb;
        $keep = max(1, min(1000, $keep));
        $sql = $wpdb->prepare(
            "SELECT id FROM `{$this->table}` WHERE protected = 0 AND status = 'verified' AND trigger_source != 'update_guard' ORDER BY created_at DESC LIMIT 18446744073709551615 OFFSET %d",
            $keep
        );
        $ids = $wpdb->get_col($sql);
        $normal = array_values(array_filter(array_map('strval', is_array($ids) ? $ids : [])));
        $oldUpdate = $wpdb->get_col(
            "SELECT id FROM `{$this->table}` WHERE protected = 0 AND trigger_source = 'update_guard' AND created_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY)"
        );
        $updates = array_values(array_filter(array_map('strval', is_array($oldUpdate) ? $oldUpdate : [])));
        return array_values(array_unique(array_merge($normal, $updates)));
    }

    private function normalize(array $row): array
    {
        $meta = json_decode((string)($row['meta'] ?? '{}'), true);
        $row['meta'] = is_array($meta) ? $meta : [];
        $row['size_bytes'] = (int)($row['size_bytes'] ?? 0);
        $row['protected'] = (bool)($row['protected'] ?? false);
        return $row;
    }
}
