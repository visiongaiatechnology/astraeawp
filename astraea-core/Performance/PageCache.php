<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Performance;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\StorageException;

/**
 * High-Performance, Zero-Dependency Disk Page Cache for AstraeaOS WP.
 *
 * Fully consent-aware (VLP integration), logged-in safe, REST immune,
 * with atomic writes and path-jailed cache management.
 *
 * @package Astraea\Performance
 */
final class PageCache {

    private static bool $started = false;
    private static ?string $cacheKey = null;
    private static ?string $cacheFile = null;

    public static function init(): void {
        if (self::shouldBypass()) {
            return;
        }

        add_action('template_redirect', [self::class, 'serveOrBuffer'], 0);
        add_action('save_post', [self::class, 'purgePost']);
        add_action('comment_post', [self::class, 'purgePost']);
    }

    /**
     * Determine if request should bypass page caching entirely.
     */
    public static function shouldBypass(): bool {
        // Only GET and HEAD requests are cacheable
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'GET' && $method !== 'HEAD') {
            return true;
        }

        // Bypass CLI, CRON, REST API, XML-RPC
        if (php_sapi_name() === 'cli' || defined('DOING_CRON') || defined('REST_REQUEST') || defined('XMLRPC_REQUEST')) {
            return true;
        }

        // Bypass Admin screens
        if (is_admin()) {
            return true;
        }

        // Bypass Logged-In Users
        if (self::hasAuthCookies()) {
            return true;
        }

        // Bypass query parameters by default (e.g. search, pagination parameters)
        if (!empty($_GET)) {
            return true;
        }

        // Bypass ecommerce carts/sessions
        foreach ($_COOKIE as $cookieName => $val) {
            if (str_starts_with($cookieName, 'woocommerce_') || str_starts_with($cookieName, 'edd_')) {
                return true;
            }
        }

        // Bypass if page cache option is disabled
        if (function_exists('get_option') && !get_option('astraea_page_cache_enabled', true)) {
            return true;
        }

        return false;
    }

    /**
     * Check if request has authentication or login cookies.
     */
    private static function hasAuthCookies(): bool {
        foreach ($_COOKIE as $name => $val) {
            if (
                str_starts_with($name, 'wordpress_logged_in_') ||
                str_starts_with($name, 'wordpress_sec_') ||
                str_starts_with($name, 'astraea_session_')
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Serve cached HTML or start output buffer.
     */
    public static function serveOrBuffer(): void {
        if (self::shouldBypass() || self::$started) {
            return;
        }

        $cacheDir = self::getCacheDirectory();
        $key = self::buildCacheKey();
        $file = $cacheDir . DIRECTORY_SEPARATOR . $key . '.html';

        self::$cacheKey = $key;
        self::$cacheFile = $file;

        // Check if valid cache exists on disk
        if (is_file($file) && is_readable($file)) {
            $mtime = filemtime($file);
            $ttl = (int)(function_exists('get_option') ? get_option('astraea_page_cache_ttl', 86400) : 86400);

            if ($mtime !== false && (time() - $mtime) < $ttl) {
                header('X-Astraea-Cache: HIT');
                header('Content-Type: text/html; charset=UTF-8');
                readfile($file);
                exit;
            }
        }

        // Start output buffering
        self::$started = true;
        ob_start([self::class, 'captureOutput']);
    }

    /**
     * Output buffer callback to save HTML page cache atomically.
     */
    public static function captureOutput(string $buffer): string {
        $status = http_response_code();
        // Only cache successful 200 OK responses
        if ($status !== 200 || strlen($buffer) < 100) {
            if (!headers_sent()) {
                header('X-Astraea-Cache: BYPASS');
            }
            return $buffer;
        }

        if (self::$cacheFile !== null) {
            self::writeCacheAtomically(self::$cacheFile, $buffer);
        }

        if (!headers_sent()) {
            header('X-Astraea-Cache: MISS');
        }

        return $buffer;
    }

    /**
     * Build cache key including host, request URI, and VLP consent receipt hash.
     */
    private static function buildCacheKey(): string {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        // VLP Consent Variation: ensure consent categories don't leak across users
        $consentState = $_COOKIE['vlp_consent'] ?? 'none';
        $consentHash = substr(hash('sha256', (string)$consentState), 0, 8);

        return hash('sha256', $scheme . '://' . $host . $uri . '|' . $consentHash);
    }

    private static function writeCacheAtomically(string $destination, string $content): void {
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $tmp = $destination . '.tmp-' . bin2hex(random_bytes(8));
        $written = @file_put_contents($tmp, $content, LOCK_EX);
        if ($written !== false && $written === strlen($content)) {
            @chmod($tmp, 0644);
            @rename($tmp, $destination);
        } else {
            @unlink($tmp);
        }
    }

    public static function purgeAll(): int {
        $dir = self::getCacheDirectory();
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.html');
        if (is_array($files)) {
            foreach ($files as $f) {
                if (is_file($f) && @unlink($f)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    public static function purgePost(mixed $postId): void {
        // Purging entire cache on post update ensures archives, feeds and home stay synchronized
        self::purgeAll();
    }

    public static function getCacheDirectory(): string {
        $base = defined('WP_CONTENT_DIR')
            ? WP_CONTENT_DIR . '/cache/astraea-page-cache'
            : sys_get_temp_dir() . '/astraea-page-cache';

        if (!is_dir($base)) {
            @mkdir($base, 0700, true);
        }
        return $base;
    }

    /**
     * Get telemetry metrics: cached files count and disk footprint in bytes.
     *
     * @return array{file_count: int, size_bytes: int}
     */
    public static function getStats(): array {
        $dir = self::getCacheDirectory();
        if (!is_dir($dir)) {
            return ['file_count' => 0, 'size_bytes' => 0];
        }

        $count = 0;
        $size = 0;
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.html');
        if (is_array($files)) {
            foreach ($files as $f) {
                if (is_file($f)) {
                    $count++;
                    $fs = filesize($f);
                    if (is_int($fs)) {
                        $size += $fs;
                    }
                }
            }
        }

        return ['file_count' => $count, 'size_bytes' => $size];
    }
}
