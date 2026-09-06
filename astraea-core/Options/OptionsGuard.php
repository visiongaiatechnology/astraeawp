<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Options;

use Astraea\Database\Connection;

/**
 * Options Table Safeguards & Hygiene Engine.
 *
 * Prevents performance degradation from:
 * - Massive autoloaded option bloat (exceeding byte budget).
 * - Stale and abandoned expired transients piling up in the options table.
 * - Oversized single options incorrectly marked for autoload.
 *
 * @package Astraea\Options
 */
final class OptionsGuard {

    public const AUTOLOAD_BUDGET_BYTES = 800000; // 800 KB warning threshold
    public const GIANT_OPTION_THRESHOLD = 65536; // 64 KB single option threshold

    /**
     * Inspect total autoload size and return metric diagnostics.
     *
     * @return array{total_bytes: int, count: int, over_budget: bool, giant_options: array<int, array{name: string, bytes: int}>}
     */
    public static function inspectAutoload(): array {
        global $wpdb;
        if (!isset($wpdb)) {
            return ['total_bytes' => 0, 'count' => 0, 'over_budget' => false, 'giant_options' => []];
        }

        $rows = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) AS value_len 
             FROM {$wpdb->options} 
             WHERE autoload = 'yes' OR autoload = 'on'",
            ARRAY_A
        );

        $totalBytes = 0;
        $count = 0;
        $giantOptions = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $len = (int) $row['value_len'];
                $totalBytes += $len;
                $count++;

                if ($len >= self::GIANT_OPTION_THRESHOLD) {
                    $giantOptions[] = [
                        'name'  => (string) $row['option_name'],
                        'bytes' => $len,
                    ];
                }
            }
        }

        return [
            'total_bytes'   => $totalBytes,
            'count'         => $count,
            'over_budget'   => $totalBytes > self::AUTOLOAD_BUDGET_BYTES,
            'giant_options' => $giantOptions,
        ];
    }

    /**
     * Clean up expired transients from the options table.
     *
     * @return int Number of purged transient records.
     */
    public static function purgeExpiredTransients(): int {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }

        $now = time();

        // 1. Delete expired transient timeouts
        $sqlTimeouts = $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like('_transient_timeout_') . '%',
            $now
        );

        $expiredTimeouts = $wpdb->get_col($sqlTimeouts);
        if (empty($expiredTimeouts)) {
            return 0;
        }

        $purgedCount = 0;
        foreach ($expiredTimeouts as $timeoutName) {
            $transientName = substr($timeoutName, strlen('_transient_timeout_'));

            delete_option($timeoutName);
            delete_option('_transient_' . $transientName);
            $purgedCount += 2;
        }

        return $purgedCount;
    }
}
