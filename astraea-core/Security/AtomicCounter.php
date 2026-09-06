<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Security;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\StorageException;

/**
 * Database-serialized fixed-window counter for anonymous resource budgets.
 */
final class AtomicCounter {
    public static function consume(string $scope, int $limit, int $windowSeconds, int $weight = 1): bool {
        if ($scope === '' || $limit < 1 || $windowSeconds < 1 || $weight < 1 || $weight > $limit) {
            throw new SecurityException('Atomic counter boundary validation failed.');
        }

        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            throw new StorageException('Atomic counter database backend unavailable.');
        }

        $digest = hash('sha256', $scope);
        $lockName = 'astraea-counter-' . substr($digest, 0, 32);
        $optionName = 'astraea_counter_' . substr($digest, 0, 40);
        $acquired = (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 2));
        if ($acquired !== 1) {
            throw new StorageException('Atomic counter lock acquisition failed.');
        }

        try {
            $now = time();
            $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
            $record = get_option($optionName, []);
            $count = 0;
            if (is_array($record) && (int)($record['window_start'] ?? -1) === $windowStart) {
                $count = max(0, (int)($record['count'] ?? 0));
            }
            if ($count > $limit - $weight) {
                return false;
            }

            $written = update_option($optionName, [
                'window_start' => $windowStart,
                'count' => $count + $weight,
            ]);
            if ($written !== true) {
                $confirmed = get_option($optionName, []);
                if (!is_array($confirmed)
                    || (int)($confirmed['window_start'] ?? -1) !== $windowStart
                    || (int)($confirmed['count'] ?? -1) !== $count + $weight) {
                    throw new StorageException('Atomic counter persistence failed.');
                }
            }
            return true;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }
}
