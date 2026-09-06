<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Performance;

use Astraea\Database\Connection;

/**
 * AstraeaOS Development Mode Request Profiler.
 *
 * Measures:
 * - Boot time & total request execution time.
 * - Peak memory allocation.
 * - Database query count, total SQL execution time, and slow queries.
 * - Total loaded PHP files.
 * - Total hook / action invocations.
 *
 * STRICTLY DISABLED in production environments by default.
 *
 * @package Astraea\Performance
 */
final class RequestProfiler {

    private static float $startTime = 0.0;
    private static bool $active = false;

    /**
     * Start profiler timer.
     */
    public static function start(): void {
        self::$startTime = defined('WP_START_TIMESTAMP') ? (float) WP_START_TIMESTAMP : microtime(true);

        // Only active if explicitly enabled or in development mode
        if (defined('ASTRAEA_PROFILER') && ASTRAEA_PROFILER === true) {
            self::$active = true;
        } elseif (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'development' && defined('WP_DEBUG') && WP_DEBUG) {
            self::$active = true;
        }

        if (self::$active) {
            Connection::getInstance()->setProfiling(true);
            register_shutdown_function([self::class, 'recordMetrics']);
        }
    }

    /**
     * Check if profiler is currently active.
     */
    public static function isActive(): bool {
        return self::$active;
    }

    /**
     * Generate snapshot report of current request metrics.
     *
     * @return array{duration_ms: float, peak_memory_mb: float, files_loaded: int, query_count: int, query_time_ms: float, slow_queries: int}
     */
    public static function snapshot(): array {
        $duration = (microtime(true) - (self::$startTime ?: microtime(true))) * 1000.0;
        $peakMemory = memory_get_peak_usage(true) / (1024 * 1024);
        $filesLoaded = count(get_included_files());

        $queryLog = Connection::getInstance()->getQueryLog();
        $queryCount = count($queryLog);
        $queryTimeMs = 0.0;
        $slowQueries = 0;

        foreach ($queryLog as $q) {
            $t = $q['time_ms'] ?? 0.0;
            $queryTimeMs += $t;
            if ($t > 50.0) { // Slow query threshold: 50ms
                $slowQueries++;
            }
        }

        return [
            'duration_ms'    => round($duration, 2),
            'peak_memory_mb' => round($peakMemory, 2),
            'files_loaded'   => $filesLoaded,
            'query_count'    => $queryCount,
            'query_time_ms'  => round($queryTimeMs, 2),
            'slow_queries'   => $slowQueries,
        ];
    }

    /**
     * Record metrics on PHP request shutdown.
     */
    public static function recordMetrics(): void {
        if (!self::$active) {
            return;
        }

        $metrics = self::snapshot();

        // Emit diagnostic header in development mode
        if (!headers_sent()) {
            header(sprintf(
                'X-Astraea-Profiler: time=%.2fms, mem=%.2fMB, files=%d, queries=%d (%.2fms)',
                $metrics['duration_ms'],
                $metrics['peak_memory_mb'],
                $metrics['files_loaded'],
                $metrics['query_count'],
                $metrics['query_time_ms']
            ));
        }
    }
}
