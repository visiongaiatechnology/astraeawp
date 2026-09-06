<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Recovery;

use Astraea\Crypto\CryptoService;
use Astraea\Crypto\KeyContext;
use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;
use Astraea\Exceptions\StorageException;

/**
 * Isolated Recovery Controller for AstraeaOS WP.
 *
 * Runs with minimal dependencies in the /astraea-recovery/ environment
 * without booting third-party WordPress plugins or themes.
 *
 * @package Astraea\Recovery
 */
final class RecoveryController {

    private const SESSION_COOKIE = 'astraea_recovery_session';

    /**
     * Authenticate emergency admin using ThroneGuard recovery key or Master key.
     * Enforces rate limiting (maximum 5 failed attempts per 15 minutes).
     */
    public static function authenticate(string $providedKey): bool {
        if (self::isRateLimited()) {
            return false;
        }

        $clean = trim($providedKey);
        if ($clean === '') {
            self::recordFailedAttempt();
            return false;
        }

        // 1. Check against Master Key Manager
        if (class_exists('\\Astraea\\Crypto\\MasterKeyManager') && \Astraea\Crypto\MasterKeyManager::isConfigured()) {
            $master = \Astraea\Crypto\MasterKeyManager::getMasterKey();
            if (hash_equals(bin2hex($master), strtolower($clean))) {
                self::clearFailedAttempts();
                self::createSession();
                return true;
            }
        }

        // 2. Check against ThroneGuard recovery key hash (stored in wp-config or option)
        $expectedHash = defined('ASTRAEA_RECOVERY_KEY_HASH') ? ASTRAEA_RECOVERY_KEY_HASH : getenv('ASTRAEA_RECOVERY_KEY_HASH');
        if (is_string($expectedHash) && $expectedHash !== '') {
            if (password_verify($clean, $expectedHash)) {
                self::clearFailedAttempts();
                self::createSession();
                return true;
            }
        }

        // 3. Fallback: Check against ThroneGuard Superkey stored in database options
        $db = self::getDatabaseConnection();
        if ($db !== null) {
            $prefix = self::getTablePrefix();
            $stmt = $db->prepare("SELECT `option_value` FROM `{$prefix}options` WHERE `option_name` = 'vis_throneguard_superkey_hash' LIMIT 1");
            if ($stmt) {
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $stmt->close();
                if ($row && !empty($row['option_value']) && is_string($row['option_value']) && password_verify($clean, $row['option_value'])) {
                    self::clearFailedAttempts();
                    self::createSession();
                    return true;
                }
            }
        }

        self::recordFailedAttempt();
        return false;
    }

    public static function isAuthenticated(): bool {
        if (!isset($_COOKIE[self::SESSION_COOKIE]) || !is_string($_COOKIE[self::SESSION_COOKIE])) {
            return false;
        }

        $parts = explode(':', $_COOKIE[self::SESSION_COOKIE], 3);
        if (count($parts) !== 3) {
            return false;
        }

        [$timestamp, $rand, $hmac] = $parts;
        if (time() - (int)$timestamp > 3600) {
            return false; // 1-hour session timeout
        }

        try {
            $expectedHmac = hash_hmac('sha256', $timestamp . ':' . $rand, self::getSessionSecret());
            return hash_equals($expectedHmac, $hmac);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function createSession(): void {
        $timestamp = (string)time();
        $rand = bin2hex(random_bytes(16));
        $hmac = hash_hmac('sha256', $timestamp . ':' . $rand, self::getSessionSecret());
        $token = $timestamp . ':' . $rand . ':' . $hmac;

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

        setcookie(self::SESSION_COOKIE, $token, [
            'expires'  => time() + 3600,
            'path'     => '/astraea-recovery/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function destroySession(): void {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

        setcookie(self::SESSION_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/astraea-recovery/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Emergency disable all third-party active plugins directly in database.
     *
     * @return array{status: string, affected: int}
     */
    public static function emergencyDisablePlugins(): array {
        self::assertAuthenticated();

        // Check if database is accessible via wp-config.php credentials
        $db = self::getDatabaseConnection();
        if ($db === null) {
            throw new StorageException('Database connection unavailable for plugin deactivation.');
        }

        $prefix = self::getTablePrefix();
        $optionsTable = $prefix . 'options';

        $stmt = $db->prepare("UPDATE `{$optionsTable}` SET `option_value` = 'a:0:{}' WHERE `option_name` = 'active_plugins'");
        if (!$stmt) {
            throw new StorageException('Failed to prepare plugin deactivation query.');
        }

        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return ['status' => 'SUCCESS', 'affected' => $affected];
    }

    /**
     * Emergency disable an Astraea Module directly in database.
     */
    public static function emergencyDisableModule(string $moduleId): array {
        self::assertAuthenticated();

        $id = function_exists('sanitize_key')
            ? sanitize_key($moduleId)
            : strtolower((string)preg_replace('/[^a-z0-9_\-]/i', '', $moduleId));
        $db = self::getDatabaseConnection();
        if ($db === null) {
            throw new StorageException('Database connection unavailable for module deactivation.');
        }

        $prefix = self::getTablePrefix();
        $optionsTable = $prefix . 'options';

        $stmt = $db->prepare("SELECT `option_value` FROM `{$optionsTable}` WHERE `option_name` = 'astraea_module_states' LIMIT 1");
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $states = [];
        if ($row && isset($row['option_value'])) {
            $unserialized = @unserialize($row['option_value'], ['allowed_classes' => false]);
            if (is_array($unserialized)) {
                $states = $unserialized;
            }
        }

        $states[$id] = false;
        $serialized = serialize($states);

        $update = $db->prepare("INSERT INTO `{$optionsTable}` (`option_name`, `option_value`, `autoload`) VALUES ('astraea_module_states', ?, 'yes') ON DUPLICATE KEY UPDATE `option_value` = ?");
        $update->bind_param('ss', $serialized, $serialized);
        $update->execute();
        $update->close();

        return ['status' => 'SUCCESS', 'module' => $id];
    }

    /**
     * Check Database Health.
     *
     * @return array{status: string, latency_ms: float, server_version: string}
     */
    public static function checkDatabase(): array {
        $start = microtime(true);
        $db = self::getDatabaseConnection();
        if ($db === null) {
            return [
                'status'         => 'DOWN',
                'latency_ms'     => 0.0,
                'server_version' => 'unavailable',
            ];
        }

        $res = $db->query("SELECT 1");
        $latency = (microtime(true) - $start) * 1000.0;
        $ver = $db->server_info ?? 'unknown';

        return [
            'status'         => ($res !== false) ? 'HEALTHY' : 'DEGRADED',
            'latency_ms'     => round($latency, 2),
            'server_version' => (string)$ver,
        ];
    }

    /**
     * Core File Integrity verification without loading WordPress core.
     *
     * @return array{status: string, checked_files: int, modified: list<string>, missing: list<string>}
     */
    public static function checkIntegrity(): array {
        $manifestFile = dirname(__DIR__, 2) . '/astraea-core/BUILD-MANIFEST.json';
        if (!is_file($manifestFile)) {
            return [
                'status'        => 'NO_MANIFEST',
                'checked_files' => 0,
                'modified'      => [],
                'missing'       => [],
            ];
        }

        $raw = file_get_contents($manifestFile);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
            return [
                'status'        => 'CORRUPT_MANIFEST',
                'checked_files' => 0,
                'modified'      => [],
                'missing'       => [],
            ];
        }

        $coreDir = dirname(__DIR__, 2) . '/astraea-core';
        $modified = [];
        $missing = [];
        $checked = 0;

        foreach ($manifest['files'] as $relPath => $expectedHash) {
            if (!is_string($relPath) || !is_string($expectedHash) || str_contains($relPath, '..')) {
                continue;
            }
            $abs = $coreDir . '/' . $relPath;
            if (!is_file($abs)) {
                $missing[] = $relPath;
                continue;
            }
            $checked++;
            $hash = hash_file('sha256', $abs);
            if (!is_string($hash) || !hash_equals($expectedHash, $hash)) {
                $modified[] = $relPath;
            }
        }

        return [
            'status'        => (empty($modified) && empty($missing)) ? 'VERIFIED' : 'TAMPERED',
            'checked_files' => $checked,
            'modified'      => $modified,
            'missing'       => $missing,
        ];
    }

    /**
     * Toggle Maintenance Lock file.
     */
    public static function toggleMaintenanceLock(bool $enable): bool {
        self::assertAuthenticated();
        $lockFile = dirname(__DIR__, 2) . '/.maintenance';

        if ($enable) {
            $content = sprintf("<?php \$upgrading = %d;\n", time());
            return (bool)file_put_contents($lockFile, $content, LOCK_EX);
        }

        if (is_file($lockFile)) {
            return @unlink($lockFile);
        }
        return true;
    }

    private static function assertAuthenticated(): void {
        if (!self::isAuthenticated()) {
            throw new SecurityException('Recovery operation requires valid emergency authentication.');
        }
    }

    public static function generateCsrfToken(): string {
        if (!self::isAuthenticated()) {
            return '';
        }
        $sessionToken = (string)($_COOKIE[self::SESSION_COOKIE] ?? '');
        return hash_hmac('sha256', 'astraea_recovery_csrf:' . $sessionToken, self::getSessionSecret());
    }

    public static function verifyCsrfToken(string $token): bool {
        if ($token === '' || !self::isAuthenticated()) {
            return false;
        }
        $expected = self::generateCsrfToken();
        return hash_equals($expected, $token);
    }

    private const THROTTLE_MAX_ATTEMPTS = 5;
    private const THROTTLE_WINDOW_SECONDS = 900; // 15 minutes

    public static function isRateLimited(): bool {
        $ip = self::getClientIp();
        $ipHash = hash('sha256', $ip);

        return (bool) self::withThrottleLock(static function (array &$data) use ($ipHash): bool {
            if (isset($data[$ipHash])) {
                $entry = $data[$ipHash];
                if (isset($entry['blocked_until']) && $entry['blocked_until'] > time()) {
                    return true;
                }
            }
            return false;
        });
    }

    public static function recordFailedAttempt(): void {
        $ip = self::getClientIp();
        $ipHash = hash('sha256', $ip);

        self::withThrottleLock(static function (array &$data) use ($ipHash): void {
            $now = time();
            if (!isset($data[$ipHash]) || ($now - $data[$ipHash]['first_attempt'] > self::THROTTLE_WINDOW_SECONDS)) {
                $data[$ipHash] = [
                    'attempts'      => 1,
                    'first_attempt' => $now,
                    'blocked_until' => 0,
                ];
            } else {
                $data[$ipHash]['attempts']++;
                if ($data[$ipHash]['attempts'] >= self::THROTTLE_MAX_ATTEMPTS) {
                    $data[$ipHash]['blocked_until'] = $now + self::THROTTLE_WINDOW_SECONDS;
                }
            }
        });
    }

    public static function clearFailedAttempts(): void {
        $ip = self::getClientIp();
        $ipHash = hash('sha256', $ip);

        self::withThrottleLock(static function (array &$data) use ($ipHash): void {
            if (isset($data[$ipHash])) {
                unset($data[$ipHash]);
            }
        });
    }

    private static function getClientIp(): string {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    private static function getThrottlePath(): string {
        $contentDir = dirname(__DIR__, 2) . '/wp-content/uploads/vgt-temp';
        if (is_dir($contentDir) || @mkdir($contentDir, 0700, true)) {
            return $contentDir . '/recovery_throttle.json';
        }
        $tempDir = sys_get_temp_dir() . '/astraea-recovery';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0700, true);
        }
        return $tempDir . '/recovery_throttle.json';
    }

    /**
     * Executes a callback with an exclusive file lock on the throttle storage.
     *
     * @param callable(array<string, array{attempts: int, first_attempt: int, blocked_until: int}>): mixed $callback
     * @return mixed
     */
    private static function withThrottleLock(callable $callback): mixed {
        $file = self::getThrottlePath();
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            throw new StorageException('Recovery throttle storage is unavailable.');
        }

        try {
            if (!@flock($fp, LOCK_EX)) {
                throw new StorageException('Recovery throttle lock acquisition failed.');
            }
            @chmod($file, 0600);
            $size = @filesize($file);
            $raw = ($size !== false && $size > 0) ? @fread($fp, $size) : '';
            $data = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $now = time();
                    foreach ($decoded as $k => $v) {
                        if (is_array($v) && isset($v['first_attempt']) && ($now - $v['first_attempt'] <= self::THROTTLE_WINDOW_SECONDS * 2)) {
                            $data[$k] = $v;
                        }
                    }
                }
            }

            $result = $callback($data);

            if (is_array($data)) {
                $encoded = json_encode($data, JSON_THROW_ON_ERROR);
                if (!@ftruncate($fp, 0) || !@rewind($fp) || @fwrite($fp, $encoded) !== strlen($encoded) || !@fflush($fp)) {
                    throw new StorageException('Recovery throttle persistence failed.');
                }
            }

            return $result;
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    private static function getSessionSecret(): string {
        if (defined('NONCE_KEY') && is_string(NONCE_KEY) && NONCE_KEY !== '') {
            return NONCE_KEY;
        }

        if (class_exists('\\Astraea\\Crypto\\MasterKeyManager') && \Astraea\Crypto\MasterKeyManager::isConfigured()) {
            return hash('sha256', \Astraea\Crypto\MasterKeyManager::getMasterKey() . 'astraea_recovery_session');
        }

        $configFile = dirname(__DIR__, 2) . '/wp-config.php';
        if (is_file($configFile)) {
            $content = file_get_contents($configFile);
            if (is_string($content)) {
                $nonceKey = self::extractConfigConstant($content, 'NONCE_KEY');
                $nonceSalt = self::extractConfigConstant($content, 'NONCE_SALT');
                if ($nonceKey !== null && $nonceKey !== '') {
                    return $nonceKey . ($nonceSalt ?? '');
                }
            }
        }

        throw new SecurityException('Cannot establish recovery session: secure salt unavailable in wp-config.php or MasterKeyManager.');
    }

    private static function getDatabaseConnection(): ?\mysqli {
        static $db = null;
        if ($db !== null) {
            return $db;
        }

        $configFile = dirname(__DIR__, 2) . '/wp-config.php';
        if (!is_file($configFile)) {
            return null;
        }

        // Read DB constants safely from wp-config.php without executing arbitrary code
        $content = file_get_contents($configFile);
        if (!is_string($content)) {
            return null;
        }

        $host = self::extractConfigConstant($content, 'DB_HOST') ?? 'localhost';
        $user = self::extractConfigConstant($content, 'DB_USER') ?? '';
        $pass = self::extractConfigConstant($content, 'DB_PASSWORD') ?? '';
        $name = self::extractConfigConstant($content, 'DB_NAME') ?? '';

        try {
            $mysqli = @new \mysqli($host, $user, $pass, $name);
            if ($mysqli->connect_errno) {
                return null;
            }
            $db = $mysqli;
            return $db;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function getTablePrefix(): string {
        $configFile = dirname(__DIR__, 2) . '/wp-config.php';
        if (is_file($configFile)) {
            $content = file_get_contents($configFile);
            if (is_string($content) && preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $m)) {
                return $m[1];
            }
        }
        return 'wp_';
    }

    private static function extractConfigConstant(string $content, string $name): ?string {
        if (preg_match('/define\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)\s*;/s', $content, $m)) {
            return $m[1];
        }
        return null;
    }
}
