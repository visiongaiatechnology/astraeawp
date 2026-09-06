<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Redirects;

use Astraea\Security\AtomicCounter;

/**
 * Privacy-Preserving 404 Error Monitor.
 *
 * Tracks missing URL paths and hit frequencies without recording raw IP addresses,
 * authentication headers, or sensitive query strings.
 *
 * @package Astraea\Redirects
 */
final class NotFoundMonitor {

    public const MAX_ENTRIES = 200;

    public static function init(): void {
        add_action('template_redirect', [self::class, 'record404'], 99);
    }

    public static function record404(): void {
        if (!is_404()) {
            return;
        }

        // 1. IP rate limiting: max 10 recorded 404s per minute per IP
        $ip = filter_var((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), FILTER_VALIDATE_IP) ?: '127.0.0.1';
        $ipKey = 'a404:ip:' . hash('sha256', $ip);
        try {
            $accepted = AtomicCounter::consume($ipKey, 10, 60);
        } catch (\Throwable) {
            return;
        }
        if (!$accepted) {
            return;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return;
        }

        // Sanitize path (strip null bytes, control characters) and cap length to 128 chars
        $cleanPath = preg_replace('/[\x00-\x1F\x7F]/', '', $path) ?? '';
        $cleanPath = mb_substr(trim($cleanPath), 0, 128);
        if ($cleanPath === '') {
            return;
        }

        try {
            $writeAccepted = AtomicCounter::consume('a404:writes:global', 500, 60);
        } catch (\Throwable) {
            return;
        }
        if (!$writeAccepted) return;

        try {
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) return;
            $table = $wpdb->prefix . 'astraea_404_events';
            $now = gmdate('Y-m-d H:i:s');
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (path_hash,path,hits,first_seen,last_seen) VALUES (%s,%s,1,%s,%s)
                 ON DUPLICATE KEY UPDATE path=VALUES(path),hits=hits+1,last_seen=VALUES(last_seen)",
                hash('sha256', $cleanPath), $cleanPath, $now, $now
            ));
            if ($wpdb->last_error !== '') return;

            $lock = 'astraea_404_trim_' . substr(hash('sha256', $wpdb->prefix), 0, 24);
            if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock)) === 1) {
                try {
                    $excess = max(0, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}") - self::MAX_ENTRIES);
                    if ($excess > 0) {
                        $wpdb->query($wpdb->prepare("DELETE FROM {$table} ORDER BY last_seen ASC LIMIT %d", $excess));
                    }
                } finally {
                    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
                }
            }
        } catch (\Throwable $e) {
            error_log('[STORAGE] 404 telemetry write failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, array{path: string, hits: int, first_seen: int, last_seen: int}>
     */
    public static function getLogs(): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return [];
        $table = $wpdb->prefix . 'astraea_404_events';
        $rows = $wpdb->get_results("SELECT path,hits,first_seen,last_seen FROM {$table} ORDER BY last_seen DESC LIMIT 200", defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows)) return [];

        $logs = [];
        foreach ($rows as $row) {
            $path = (string)($row['path'] ?? '');
            if ($path === '') continue;
            $logs[$path] = [
                'path' => $path,
                'hits' => (int)($row['hits'] ?? 0),
                'first_seen' => strtotime((string)($row['first_seen'] ?? '')) ?: 0,
                'last_seen' => strtotime((string)($row['last_seen'] ?? '')) ?: 0,
            ];
        }
        return $logs;
    }

    public static function clearLogs(): void {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        $table = $wpdb->prefix . 'astraea_404_events';
        $wpdb->query("DELETE FROM {$table}");
        delete_option('astraea_404_logs');
    }
}
