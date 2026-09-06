<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Backup;

use Astraea\Vault\Exception\StorageException;
use Astraea\Vault\Exception\SecurityException;

final class DatabaseExporter
{
    private const BATCH_ROWS = 50;

    public function writeAll(ContainerWriter $writer): array
    {
        global $wpdb;
        if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false) {
            throw new StorageException('Unable to configure database snapshot isolation.');
        }
        if ($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') === false) {
            throw new StorageException('Unable to start consistent database snapshot.');
        }
        try {
            $like = $wpdb->esc_like($wpdb->prefix) . '%';
            $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
            if (!is_array($tables)) {
                throw new StorageException('Unable to enumerate WordPress database tables.');
            }
            $excluded = [
                $wpdb->prefix . 'astraea_vault_backups',
                $wpdb->prefix . 'astraea_vault_incidents',
            ];
            $stats = ['tables' => 0, 'rows' => 0, 'consistency' => 'repeatable-read'];
            foreach ($tables as $tableValue) {
                $table = (string)$tableValue;
                if (in_array($table, $excluded, true)) { continue; }
                $this->assertIdentifier($table);
                $createRow = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
                if (!is_array($createRow) || !isset($createRow[1]) || !is_string($createRow[1])) {
                    throw new StorageException('Unable to read database table schema.');
                }
                $writer->writeRecord(ContainerWriter::TYPE_DB_TABLE, json_encode([
                    'table' => $table,
                    'create_sql' => $createRow[1],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);

                $primary = $this->singlePrimaryKey($table);
                $rows = $primary !== null
                    ? $this->exportByPrimaryKey($writer, $table, $primary)
                    : $this->exportByOffset($writer, $table);
                $stats['tables']++;
                $stats['rows'] += $rows;
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new StorageException('Unable to commit database snapshot transaction.');
            }
            return $stats;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private function exportByPrimaryKey(ContainerWriter $writer, string $table, string $primary): int
    {
        global $wpdb;
        $count = 0;
        $last = null;
        while (true) {
            if ($last === null) {
                $sql = $wpdb->prepare("SELECT * FROM `{$table}` ORDER BY `{$primary}` ASC LIMIT %d", self::BATCH_ROWS);
            } else {
                $sql = $wpdb->prepare("SELECT * FROM `{$table}` WHERE `{$primary}` > %s ORDER BY `{$primary}` ASC LIMIT %d", (string)$last, self::BATCH_ROWS);
            }
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (!is_array($rows)) {
                throw new StorageException('Database row export failed.');
            }
            if ($rows === []) { break; }
            $this->writeRows($writer, $table, $rows);
            $count += count($rows);
            $last = $rows[array_key_last($rows)][$primary] ?? null;
            if ($last === null || count($rows) < self::BATCH_ROWS) { break; }
        }
        return $count;
    }

    private function exportByOffset(ContainerWriter $writer, string $table): int
    {
        global $wpdb;
        $offset = 0;
        $count = 0;
        while (true) {
            $sql = $wpdb->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", self::BATCH_ROWS, $offset);
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (!is_array($rows)) {
                throw new StorageException('Database row export failed.');
            }
            if ($rows === []) { break; }
            $this->writeRows($writer, $table, $rows);
            $batch = count($rows);
            $count += $batch;
            $offset += $batch;
            if ($batch < self::BATCH_ROWS) { break; }
        }
        return $count;
    }

    private function writeRows(ContainerWriter $writer, string $table, array $rows): void
    {
        if (count($rows) === 1 && $this->rawRowBytes($rows[0]) > 8 * 1024 * 1024) {
            $this->writeLargeRow($writer, $table, $rows[0]);
            return;
        }
        $encoded = [];
        foreach ($rows as $row) {
            $safeRow = [];
            foreach ($row as $column => $value) {
                if (!is_string($column) || preg_match('/^[A-Za-z0-9_$]+$/D', $column) !== 1) {
                    throw new SecurityException('Database column validation failed.');
                }
                $safeRow[$column] = $value === null ? null : base64_encode((string)$value);
            }
            $encoded[] = $safeRow;
        }
        $payload = json_encode(['table' => $table, 'rows' => $encoded], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($payload) > 12 * 1024 * 1024) {
            if (count($rows) === 1) {
                $this->writeLargeRow($writer, $table, $rows[0]);
                return;
            }
            $half = max(1, intdiv(count($rows), 2));
            $this->writeRows($writer, $table, array_slice($rows, 0, $half));
            $this->writeRows($writer, $table, array_slice($rows, $half));
            return;
        }
        $writer->writeRecord(ContainerWriter::TYPE_DB_ROWS, $payload, true);
    }

    private function writeLargeRow(ContainerWriter $writer, string $table, array $row): void
    {
        $columns = [];
        $nulls = [];
        foreach ($row as $column => $value) {
            if (!is_string($column) || preg_match('/^[A-Za-z0-9_$]+$/D', $column) !== 1) {
                throw new SecurityException('Database column validation failed.');
            }
            $columns[] = $column;
            if ($value === null) { $nulls[] = $column; }
        }
        $writer->writeRecord(ContainerWriter::TYPE_DB_ROW_START, json_encode([
            'table' => $table,
            'columns' => $columns,
            'nulls' => $nulls,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $cells = [];
        foreach ($row as $column => $value) {
            if ($value === null) {
                $cells[$column] = ['size' => 0, 'sha256' => null];
                continue;
            }
            $raw = (string)$value;
            $total = strlen($raw);
            $hash = hash_init('sha256');
            $offset = 0;
            while ($offset < $total) {
                $chunk = substr($raw, $offset, 1048576);
                hash_update($hash, $chunk);
                $meta = json_encode([
                    'column' => $column,
                    'offset' => $offset,
                    'total' => $total,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $payload = pack('N', strlen($meta)) . $meta . $chunk;
                $writer->writeRecord(ContainerWriter::TYPE_DB_ROW_CELL, $payload, true);
                $offset += strlen($chunk);
            }
            if ($total === 0) {
                $meta = json_encode(['column' => $column, 'offset' => 0, 'total' => 0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $writer->writeRecord(ContainerWriter::TYPE_DB_ROW_CELL, pack('N', strlen($meta)) . $meta, false);
            }
            $cells[$column] = ['size' => $total, 'sha256' => hash_final($hash)];
        }
        $writer->writeRecord(ContainerWriter::TYPE_DB_ROW_END, json_encode([
            'table' => $table,
            'cells' => $cells,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
    }

    private function rawRowBytes(array $row): int
    {
        $bytes = 0;
        foreach ($row as $value) {
            if ($value !== null) {
                $bytes += strlen((string)$value);
                if ($bytes > 8 * 1024 * 1024) { break; }
            }
        }
        return $bytes;
    }

    private function singlePrimaryKey(string $table): ?string
    {
        global $wpdb;
        $rows = $wpdb->get_results("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if (!is_array($rows) || count($rows) !== 1) {
            return null;
        }
        $column = (string)($rows[0]['Column_name'] ?? '');
        if (preg_match('/^[A-Za-z0-9_$]+$/D', $column) !== 1) {
            return null;
        }
        return $column;
    }

    private function assertIdentifier(string $table): void
    {
        global $wpdb;
        if (!str_starts_with($table, $wpdb->prefix) || preg_match('/^[A-Za-z0-9_$]+$/D', $table) !== 1) {
            throw new SecurityException('Database table validation failed.');
        }
    }
}
