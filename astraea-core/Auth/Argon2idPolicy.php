<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Auth;

/**
 * Adaptive Argon2id Configuration & Policy Manager.
 *
 * Enforces safe boundaries, prevents resource exhaustion DoS attacks,
 * and provides a calibration benchmark for server tuning.
 *
 * @package Astraea\Auth
 */
final class Argon2idPolicy {

    public const POLICY_VERSION = 'argon2id-v1';

    // Safe default parameters (aiming for ~100-200ms on modern server CPUs)
    public const DEFAULT_MEMORY_COST = 65536; // 64 MB
    public const DEFAULT_TIME_COST   = 4;     // 4 iterations
    public const DEFAULT_THREADS     = 1;     // 1 thread

    // Guardrail boundaries to prevent configuration mistakes or DoS
    public const MIN_MEMORY_COST = 16384;  // 16 MB minimum
    public const MAX_MEMORY_COST = 262144; // 256 MB maximum
    public const MIN_TIME_COST   = 2;      // 2 iterations minimum
    public const MAX_TIME_COST   = 10;     // 10 iterations maximum
    public const MIN_THREADS     = 1;      // 1 thread minimum
    public const MAX_THREADS     = 4;      // 4 threads maximum

    /**
     * Get the active options array for password_hash().
     *
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    public static function getOptions(): array {
        $memory = (int) (defined('ASTRAEA_ARGON2ID_MEMORY') ? ASTRAEA_ARGON2ID_MEMORY : self::DEFAULT_MEMORY_COST);
        $time   = (int) (defined('ASTRAEA_ARGON2ID_TIME') ? ASTRAEA_ARGON2ID_TIME : self::DEFAULT_TIME_COST);
        $threads = (int) (defined('ASTRAEA_ARGON2ID_THREADS') ? ASTRAEA_ARGON2ID_THREADS : self::DEFAULT_THREADS);

        // Clamp to enforced guardrail bounds
        $clampedMemory = max(self::MIN_MEMORY_COST, min(self::MAX_MEMORY_COST, $memory));
        $clampedTime   = max(self::MIN_TIME_COST, min(self::MAX_TIME_COST, $time));
        $clampedThreads = max(self::MIN_THREADS, min(self::MAX_THREADS, $threads));

        return [
            'memory_cost' => $clampedMemory,
            'time_cost'   => $clampedTime,
            'threads'     => $clampedThreads,
        ];
    }

    /**
     * Measure the execution time of hashing with given or default parameters.
     *
     * @param array<string, int>|null $customOptions
     * @return array{duration_ms: float, memory_mb: float, options: array<string, int>}
     */
    public static function benchmark(?array $customOptions = null): array {
        $options = $customOptions ?? self::getOptions();
        $samplePassword = 'AstraeaOS_Benchmark_Password_Entropy_#2026';

        $start = microtime(true);
        $hash = password_hash($samplePassword, PASSWORD_ARGON2ID, $options);
        $duration = (microtime(true) - $start) * 1000.0;

        return [
            'duration_ms' => round($duration, 2),
            'memory_mb'   => round($options['memory_cost'] / 1024, 1),
            'options'     => $options,
            'hash_sample' => substr($hash, 0, 30) . '...',
        ];
    }

    /**
     * Calibrate optimal Argon2id parameters aiming for a target duration in milliseconds.
     * Default target is 150ms.
     *
     * @param float $targetMs Desired hashing duration in milliseconds.
     * @return array{recommended_options: array<string, int>, benchmark: array<string, mixed>}
     */
    public static function calibrate(float $targetMs = 150.0): array {
        $memory = self::DEFAULT_MEMORY_COST;
        $threads = 1;
        $time = 3;

        // Try candidate parameters
        $options = ['memory_cost' => $memory, 'time_cost' => $time, 'threads' => $threads];
        $bench = self::benchmark($options);

        if ($bench['duration_ms'] < ($targetMs * 0.7) && $time < self::MAX_TIME_COST) {
            $time++;
            $options['time_cost'] = $time;
            $bench = self::benchmark($options);
        } elseif ($bench['duration_ms'] > ($targetMs * 1.4) && $time > self::MIN_TIME_COST) {
            $time--;
            $options['time_cost'] = $time;
            $bench = self::benchmark($options);
        }

        return [
            'recommended_options' => $options,
            'benchmark'           => $bench,
        ];
    }
}
