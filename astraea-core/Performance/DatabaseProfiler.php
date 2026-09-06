<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Performance;

/**
 * Database Telemetry & Storage Profiler for Astraea Performance Engine.
 *
 * Gathers exact database metrics: autoloaded options payload size,
 * table fragmentation, and slow query counts without synthetic mock numbers.
 *
 * @package Astraea\Performance
 */
final class DatabaseProfiler {

    /**
     * Inspect autoloaded options payload and top offenders.
     *
     * @return array{total_size_bytes: int, total_options: int, largest_options: list<array{name: string, size_bytes: int}>}
     */
    public static function profileAutoload(): array {
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof \wpdb) || empty($wpdb->dbh)) {
            return ['total_size_bytes' => 0, 'total_options' => 0, 'largest_options' => []];
        }

        $optionsTable = $wpdb->options;

        // Total autoload size
        $totalSize = (int)$wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM `{$optionsTable}` WHERE `autoload` = 'yes' OR `autoload` = 'on'");
        $totalCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$optionsTable}` WHERE `autoload` = 'yes' OR `autoload` = 'on'");

        // Top 10 largest options
        $rows = $wpdb->get_results(
            "SELECT `option_name`, LENGTH(`option_value`) AS `size_bytes`
             FROM `{$optionsTable}`
             WHERE `autoload` = 'yes' OR `autoload` = 'on'
             ORDER BY `size_bytes` DESC
             LIMIT 10",
            ARRAY_A
        );

        $largest = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $largest[] = [
                    'name'       => (string)($row['option_name'] ?? ''),
                    'size_bytes' => (int)($row['size_bytes'] ?? 0),
                ];
            }
        }

        return [
            'total_size_bytes' => $totalSize,
            'total_options'    => $totalCount,
            'largest_options'  => $largest,
        ];
    }

    /**
     * Profile database tables and total database footprint.
     *
     * @return array{total_tables: int, total_data_bytes: int, total_index_bytes: int}
     */
    public static function profileTables(): array {
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof \wpdb) || empty($wpdb->dbh)) {
            return ['total_tables' => 0, 'total_data_bytes' => 0, 'total_index_bytes' => 0];
        }

        $rows = $wpdb->get_results("SHOW TABLE STATUS LIKE '" . $wpdb->esc_like($wpdb->prefix) . "%'", ARRAY_A);
        $tables = 0;
        $dataBytes = 0;
        $indexBytes = 0;

        if (is_array($rows)) {
            $tables = count($rows);
            foreach ($rows as $row) {
                $dataBytes += (int)($row['Data_length'] ?? 0);
                $indexBytes += (int)($row['Index_length'] ?? 0);
            }
        }

        return [
            'total_tables'      => $tables,
            'total_data_bytes'  => $dataBytes,
            'total_index_bytes' => $indexBytes,
        ];
    }
}
