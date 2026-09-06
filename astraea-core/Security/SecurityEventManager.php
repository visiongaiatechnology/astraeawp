<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Security;

use Astraea\Auth\SessionManager;

/**
 * Centralized evidence-only security audit trail.
 *
 * No synthetic bootstrap events are generated. Empty means empty.
 */
final class SecurityEventManager {

    public const OPTION_KEY = 'astraea_security_events_ring';
    public const MAX_EVENTS = 200;

    public const SEVERITY_INFO = 'INFO';
    public const SEVERITY_WARNING = 'WARNING';
    public const SEVERITY_CRITICAL = 'CRITICAL';

    public static function recordEvent(
        string $severity,
        string $subsystem,
        string $action,
        string $details,
        array $context = []
    ): void {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        $severity = strtoupper(trim($severity));
        if (!in_array($severity, [self::SEVERITY_INFO, self::SEVERITY_WARNING, self::SEVERITY_CRITICAL], true)) {
            $severity = self::SEVERITY_INFO;
        }

        $ip = '';
        if (!empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            $ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : '';
        }

        $event = [
            'id' => bin2hex(random_bytes(8)),
            'timestamp' => time(),
            'datetime' => gmdate('c'),
            'severity' => $severity,
            'subsystem' => self::sanitizeToken($subsystem, 48),
            'action' => self::sanitizeToken($action, 64),
            'details' => self::sanitizeString($details),
            'context' => self::sanitizeContext($context),
            'ip' => SessionManager::maskIp($ip),
        ];

        $events = get_option(self::OPTION_KEY, []);
        if (!is_array($events)) {
            $events = [];
        }

        array_unshift($events, $event);
        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, 0, self::MAX_EVENTS);
        }
        update_option(self::OPTION_KEY, $events, false);
    }

    public static function recordOnce(
        string $severity,
        string $subsystem,
        string $action,
        string $details,
        array $context = [],
        int $dedupeWindowSeconds = 60
    ): void {
        $dedupeWindowSeconds = max(1, min(3600, $dedupeWindowSeconds));
        $fingerprint = hash('sha256', strtoupper($severity) . '|' . $subsystem . '|' . $action . '|' . $details . '|' . self::stableContext($context));
        $transient = 'astraea_evt_' . substr($fingerprint, 0, 32);
        if (function_exists('get_transient') && get_transient($transient) !== false) {
            return;
        }
        if (function_exists('set_transient')) {
            set_transient($transient, 1, $dedupeWindowSeconds);
        }
        self::recordEvent($severity, $subsystem, $action, $details, $context);
    }

    /** @return array<int,array<string,mixed>> */
    public static function getRecentEvents(int $limit = 50, ?string $severityFilter = null): array {
        if (!function_exists('get_option')) {
            return [];
        }
        $events = get_option(self::OPTION_KEY, []);
        if (!is_array($events)) {
            return [];
        }

        if ($severityFilter !== null && $severityFilter !== '') {
            $severityFilter = strtoupper(trim($severityFilter));
            $events = array_filter($events, static fn($ev): bool => is_array($ev) && ($ev['severity'] ?? '') === $severityFilter);
        }

        return array_slice(array_values(array_filter($events, 'is_array')), 0, max(1, min($limit, self::MAX_EVENTS)));
    }

    public static function clearEvents(): void {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_KEY);
        }
    }

    private static function sanitizeToken(string $value, int $limit): string {
        $clean = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', trim($value)) ?? 'event';
        $clean = trim($clean, '_');
        return substr($clean !== '' ? $clean : 'event', 0, $limit);
    }

    private static function sanitizeString(string $input): string {
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input) ?? '';
        $value = preg_replace(
            '/\b(password|passphrase|token|secret|api[_-]?key|access[_-]?key|refresh[_-]?token|authorization|cookie|session|nonce)\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $input
        );
        $value = is_string($value) ? $value : $input;
        $scrubbedBearer = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value);
        $value = is_string($scrubbedBearer) ? $scrubbedBearer : $value;
        return substr(trim($value), 0, 4000);
    }

    /** @return array<string,mixed> */
    private static function sanitizeContext(array $context, int $depth = 0): array {
        if ($depth >= 4) {
            return ['depth' => '[TRUNCATED]'];
        }
        $sensitive = ['password', 'passphrase', 'secret', 'token', 'key', 'cookie', 'authorization', 'session', 'nonce', 'credential'];
        $clean = [];
        $count = 0;
        foreach ($context as $key => $value) {
            if (++$count > 30) {
                $clean['truncated'] = true;
                break;
            }
            $name = is_string($key) ? strtolower($key) : (string)$key;
            $isSensitive = false;
            foreach ($sensitive as $needle) {
                if (str_contains($name, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                $clean[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $clean[$key] = self::sanitizeContext($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = self::sanitizeString(substr($value, 0, 512));
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = get_debug_type($value);
            }
        }
        return $clean;
    }

    private static function stableContext(array $context): string {
        $clean = self::sanitizeContext($context);
        ksort($clean);
        try {
            return json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return '';
        }
    }
}
