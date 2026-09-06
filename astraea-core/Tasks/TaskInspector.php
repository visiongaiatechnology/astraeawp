<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Tasks;

/**
 * Task and Cron Schedule Inspector for Astraea Task Center.
 *
 * Inspects all scheduled events across Astraea Core, WordPress Core, and plugins,
 * calculating overdue delays and schedule safety metrics without synthetic data.
 *
 * @package Astraea\Tasks
 */
final class TaskInspector {

    /**
     * Enumerate all scheduled tasks with diagnostics.
     *
     * @return list<array<string, mixed>>
     */
    public static function getTasks(): array {
        $cron = _get_cron_array();
        if (!is_array($cron)) {
            return [];
        }

        $schedules = wp_get_schedules();
        $now = time();
        $tasks = [];

        foreach ($cron as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }

            foreach ($hooks as $hook => $events) {
                if (!is_array($events)) {
                    continue;
                }

                foreach ($events as $sig => $data) {
                    $schedule = $data['schedule'] ?? false;
                    $interval = 0;
                    $recurrence = 'One-off';

                    if (is_string($schedule) && isset($schedules[$schedule])) {
                        $interval = (int)($schedules[$schedule]['interval'] ?? 0);
                        $recurrence = (string)($schedules[$schedule]['display'] ?? $schedule);
                    }

                    $source = self::detectSource($hook);
                    $isOverdue = ($now - $timestamp) > 600; // 10 minutes overdue
                    $isHighFreq = ($interval > 0 && $interval < 60);

                    $tasks[] = [
                        'hook'         => $hook,
                        'source'       => $source,
                        'next_run'     => (int)$timestamp,
                        'recurrence'   => $recurrence,
                        'interval_sec' => $interval,
                        'args'         => $data['args'] ?? [],
                        'is_overdue'   => $isOverdue,
                        'is_high_freq' => $isHighFreq,
                    ];
                }
            }
        }

        // Sort by next_run ascending
        usort($tasks, static fn(array $a, array $b): int => $a['next_run'] <=> $b['next_run']);

        return $tasks;
    }

    /**
     * Enumerate all scheduled tasks with diagnostics (alias of getTasks).
     *
     * @return list<array<string, mixed>>
     */
    public static function inspect(): array {
        return self::getTasks();
    }

    /**
     * Dispatch an individual scheduled cron hook immediately.
     */
    public static function runNow(string $hook, array $args = []): bool {
        $cron = function_exists('_get_cron_array') ? _get_cron_array() : [];
        $valid = false;
        if (is_array($cron)) {
            foreach ($cron as $events) {
                if (is_array($events) && isset($events[$hook])) {
                    $valid = true;
                    break;
                }
            }
        }

        if (!$valid && (str_starts_with($hook, 'astraea_') || str_starts_with($hook, 'vis_') || str_starts_with($hook, 'vgt_'))) {
            $valid = true;
        }

        if (!$valid || !has_action($hook)) {
            return false;
        }

        do_action_ref_array($hook, $args);
        return true;
    }

    private static function detectSource(string $hook): string {
        if (str_starts_with($hook, 'astraea_') || str_starts_with($hook, 'vis_') || str_starts_with($hook, 'vgt_')) {
            return 'Astraea Core';
        }
        if (str_starts_with($hook, 'wp_')) {
            return 'WordPress Core';
        }
        return 'Plugin / Extension';
    }
}
