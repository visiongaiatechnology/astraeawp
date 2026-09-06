<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Recovery;

use Astraea\Security\SecurityEventManager;
use Astraea\Security\Logger;

/**
 * Boot Failure and Loop Prevention Detector for AstraeaOS WP.
 *
 * Detects repeated fatal boots (e.g. 3 consecutive incomplete boots)
 * and engages the recovery gate to prevent endless crash loops.
 *
 * @package Astraea\Recovery
 */
final class BootFailureDetector {

    public const MAX_BOOT_FAILURES = 3;
    private const COUNTER_FILE = 'boot_failures.json';

    private static bool $engaged = false;
    private static bool $completedNormally = false;

    /**
     * Call at the earliest phase of boot (Phase A) to track boot attempt.
     */
    public static function trackBootStart(): void {
        if (self::$engaged) {
            return;
        }
        self::$engaged = true;

        $state = self::readState();
        $now = time();

        // If last attempt was more than 10 minutes ago, reset stale counter
        if ($now - ($state['last_attempt'] ?? 0) > 600) {
            $state['count'] = 0;
        }

        $state['count'] = ($state['count'] ?? 0) + 1;
        $state['last_attempt'] = $now;
        $state['in_flight'] = true;

        self::writeState($state);

        register_shutdown_function([self::class, 'handleShutdown']);

        if ($state['count'] >= self::MAX_BOOT_FAILURES) {
            self::engageRecoveryGate($state['count']);
        }
    }

    /**
     * Call at successful completion of normal lifecycle (Phase E / template_redirect).
     */
    public static function markBootSuccessful(): void {
        self::$completedNormally = true;
        $state = self::readState();
        if (($state['count'] ?? 0) > 0 || ($state['in_flight'] ?? false)) {
            $state['count'] = 0;
            $state['in_flight'] = false;
            $state['last_success'] = time();
            self::writeState($state);
        }
    }

    public static function handleShutdown(): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $state = self::readState();
            $state['in_flight'] = false;
            $state['last_fatal'] = [
                'type' => $error['type'],
                'message' => substr($error['message'], 0, 256),
                'file' => basename($error['file']),
                'line' => $error['line'],
                'timestamp' => time(),
            ];
            self::writeState($state);

            if (class_exists(SecurityEventManager::class)) {
                SecurityEventManager::recordOnce(
                    SecurityEventManager::SEVERITY_CRITICAL,
                    'Recovery',
                    'boot_fatal_error',
                    'Fatal error caught during boot. Failure count: ' . ($state['count'] ?? 1),
                    [],
                    60
                );
            }
        } elseif (self::$completedNormally) {
            self::markBootSuccessful();
        }
    }

    public static function isRecoveryRecommended(): bool {
        $state = self::readState();
        return ($state['count'] ?? 0) >= self::MAX_BOOT_FAILURES;
    }

    public static function getFailureCount(): int {
        $state = self::readState();
        return (int)($state['count'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getLastFatal(): ?array {
        $state = self::readState();
        return $state['last_fatal'] ?? null;
    }

    public static function reset(): void {
        self::$engaged = false;
        self::$completedNormally = false;
        self::writeState(['count' => 0, 'in_flight' => false, 'last_attempt' => time()]);
    }

    public static function disengageForTest(): void {
        self::$engaged = false;
    }

    private static function engageRecoveryGate(int $failureCount): void {
        // Log critical security event
        if (class_exists(Logger::class)) {
            Logger::critical(sprintf('[RecoveryGate] %d consecutive boot failures detected.', $failureCount));
        }

        // Only redirect web requests (not CLI or AJAX/REST requests)
        if (php_sapi_name() !== 'cli' && !defined('DOING_CRON') && !defined('REST_REQUEST')) {
            $recoveryUrl = (defined('WP_CONTENT_URL') ? dirname(WP_CONTENT_URL) : '') . '/astraea-recovery/';
            if ($recoveryUrl !== '' && !headers_sent()) {
                http_response_code(503);
                header('Retry-After: 300');
                header('X-Astraea-Recovery-Gate: Engaged');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function readState(): array {
        $path = self::getStatePath();
        if (!is_file($path) || !is_readable($path)) {
            return ['count' => 0, 'in_flight' => false, 'last_attempt' => 0];
        }
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : ['count' => 0, 'in_flight' => false, 'last_attempt' => 0];
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function writeState(array $state): void {
        $path = self::getStatePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    private static function getStatePath(): string {
        $uploadDir = defined('WP_CONTENT_DIR')
            ? WP_CONTENT_DIR . '/uploads/vgt-temp'
            : sys_get_temp_dir() . '/astraea-recovery';
        return $uploadDir . DIRECTORY_SEPARATOR . self::COUNTER_FILE;
    }
}
