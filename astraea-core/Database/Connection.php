<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Database;

use Astraea\Exceptions\StorageException;
use wpdb;

/**
 * AstraeaOS Modern Database Access Layer.
 *
 * Provides a hardened, strictly-parameterized interface over the underlying database
 * with native transaction support, query timing, and zero SQL-string concatenation with user input.
 * Adheres to VGT Pattern 1.5.A (StorageException hierarchy) and Section 3.5.
 *
 * Fully compatible with WordPress $wpdb.
 *
 * @package Astraea\Database
 */
final class Connection {

    private static ?Connection $instance = null;
    private ?object $wpdb = null;
    /** @var array<array{sql: string, params: array<int|string, mixed>, time_ms: float}> */
    private array $queryLog = [];
    private bool $profiling = false;

    public function __construct(?object $wpdb = null) {
        if ($wpdb !== null) {
            $this->wpdb = $wpdb;
        } elseif (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $this->wpdb = $GLOBALS['wpdb'];
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getWpdb(): object {
        if ($this->wpdb === null) {
            if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
                $this->wpdb = $GLOBALS['wpdb'];
            } else {
                throw new StorageException('WordPress database object ($wpdb) is not initialized.');
            }
        }
        return $this->wpdb;
    }

    /**
     * Enable or disable query profiling in memory.
     */
    public function setProfiling(bool $enabled): void {
        $this->profiling = $enabled;
    }

    /**
     * Execute a strictly parameterized query returning all matching rows.
     *
     * @param string $sql Prepared SQL query with %s, %d, %f placeholders.
     * @param array<int, mixed> $params Bound parameters.
     * @return array<int, array<string, mixed>> Result rows as associative arrays.
     * @throws StorageException On database execution error.
     */
    public function query(string $sql, array $params = []): array {
        $db = $this->getWpdb();
        $prepared = !empty($params) ? $db->prepare($sql, ...$params) : $sql;

        $start = microtime(true);
        $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $results = $db->get_results($prepared, $outputType);
        $duration = (microtime(true) - $start) * 1000.0;

        if ($this->profiling) {
            $this->queryLog[] = [
                'sql'     => $sql,
                'params'  => $this->redactParams($params),
                'time_ms' => round($duration, 2),
            ];
        }

        if ($db->last_error !== '') {
            error_log(sprintf('[STORAGE] Database query error: %s [Query: %s]', $db->last_error, $sql));
            throw new StorageException('Database query operation failed.', 0, null, [
                'error'  => $db->last_error,
                'sql'    => $sql,
                'params' => $this->redactParams($params),
            ]);
        }

        return is_array($results) ? $results : [];
    }

    /**
     * Fetch a single row as an associative array.
     *
     * @param string $sql Prepared SQL query.
     * @param array<int, mixed> $params Bound parameters.
     * @return array<string, mixed>|null
     */
    public function queryRow(string $sql, array $params = []): ?array {
        $rows = $this->query($sql, $params);
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Fetch a single scalar value.
     *
     * @param string $sql Prepared SQL query.
     * @param array<int, mixed> $params Bound parameters.
     * @return mixed
     * @throws StorageException On database execution error.
     */
    public function queryVar(string $sql, array $params = []): mixed {
        $db = $this->getWpdb();
        $prepared = !empty($params) ? $db->prepare($sql, ...$params) : $sql;

        $start = microtime(true);
        $var = $db->get_var($prepared);
        $duration = (microtime(true) - $start) * 1000.0;

        if ($this->profiling) {
            $this->queryLog[] = [
                'sql'     => $sql,
                'params'  => $this->redactParams($params),
                'time_ms' => round($duration, 2),
            ];
        }

        if ($db->last_error !== '') {
            error_log(sprintf('[STORAGE] Database query error: %s [Query: %s]', $db->last_error, $sql));
            throw new StorageException('Database query operation failed.', 0, null, [
                'error'  => $db->last_error,
                'sql'    => $sql,
                'params' => $this->redactParams($params),
            ]);
        }

        return $var;
    }

    /**
     * Execute an INSERT, UPDATE, or DELETE statement.
     *
     * @param string $sql Prepared SQL query.
     * @param array<int, mixed> $params Bound parameters.
     * @return int Number of affected rows.
     * @throws StorageException On database execution error.
     */
    public function execute(string $sql, array $params = []): int {
        $db = $this->getWpdb();
        $prepared = !empty($params) ? $db->prepare($sql, ...$params) : $sql;

        $start = microtime(true);
        $affected = $db->query($prepared);
        $duration = (microtime(true) - $start) * 1000.0;

        if ($this->profiling) {
            $this->queryLog[] = [
                'sql'     => $sql,
                'params'  => $this->redactParams($params),
                'time_ms' => round($duration, 2),
            ];
        }

        if ($db->last_error !== '') {
            error_log(sprintf('[STORAGE] Database execution error: %s [Query: %s]', $db->last_error, $sql));
            throw new StorageException('Database execution operation failed.', 0, null, [
                'error'  => $db->last_error,
                'sql'    => $sql,
                'params' => $this->redactParams($params),
            ]);
        }

        return is_int($affected) ? $affected : 0;
    }

    /**
     * Begin an ACID transaction.
     */
    public function beginTransaction(): void {
        $this->getWpdb()->query('START TRANSACTION');
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): void {
        $this->getWpdb()->query('COMMIT');
    }

    /**
     * Roll back the current transaction.
     */
    public function rollback(): void {
        $this->getWpdb()->query('ROLLBACK');
    }

    /**
     * Execute a closure inside a database transaction.
     * Automatically commits on success and rolls back on exception.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed {
        $this->beginTransaction();
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Get recorded queries from the profiler.
     *
     * @return array<array{sql: string, params: array<int|string, mixed>, time_ms: float}>
     */
    public function getQueryLog(): array {
        return $this->queryLog;
    }

    /**
     * Redact parameter values to prevent accidental secret leakage into logs or exceptions.
     *
     * @param array<int|string, mixed> $params
     * @return array<int|string, string>
     */
    private function redactParams(array $params): array {
        $redacted = [];
        foreach ($params as $key => $val) {
            $type = gettype($val);
            $redacted[$key] = "[PARAM:{$type}]";
        }
        return $redacted;
    }
}
