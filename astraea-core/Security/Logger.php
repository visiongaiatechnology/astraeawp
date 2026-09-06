<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Security;

/**
 * Structured Security & Audit Logger for AstraeaOS WP.
 *
 * Implements:
 * - Deterministic log levels: DEBUG, INFO, NOTICE, WARNING, ERROR, SECURITY, CRITICAL.
 * - Automatic deep-redaction of sensitive secrets (passwords, tokens, master keys, auth headers).
 * - Normalized JSON log structure adhering to VGT Section 3.1 (JSON_THROW_ON_ERROR).
 * - Direct integration with PHP error_log sink.
 *
 * @package Astraea\Security
 */
final class Logger {

    public const LEVEL_DEBUG    = 'DEBUG';
    public const LEVEL_INFO     = 'INFO';
    public const LEVEL_NOTICE   = 'NOTICE';
    public const LEVEL_WARNING  = 'WARNING';
    public const LEVEL_ERROR    = 'ERROR';
    public const LEVEL_SECURITY = 'SECURITY';
    public const LEVEL_CRITICAL = 'CRITICAL';

    private const SENSITIVE_KEYS = [
        'password', 'pass', 'user_pass', 'secret', 'token', 'authorization',
        'master_key', 'api_key', 'private_key', 'cookie', 'session',
        'nonce', 'auth', 'key', 'code', 'sig', 'signature'
    ];

    /**
     * Log a message with structured metadata.
     *
     * @param string $level Log level.
     * @param string $message Descriptive log message.
     * @param array<string, mixed> $context Additional contextual data.
     */
    public static function log(string $level, string $message, array $context = []): void {
        $redactedContext = self::redact($context);

        $entry = [
            'timestamp'  => gmdate('Y-m-d\TH:i:s\Z'),
            'level'      => strtoupper($level),
            'system'     => 'AstraeaOS-WP',
            'message'    => $message,
            'context'    => $redactedContext,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'uri'        => self::sanitizeUri($_SERVER['REQUEST_URI'] ?? null),
        ];

        try {
            $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            error_log('[AstraeaOS] ' . $json);
        } catch (\JsonException $e) {
            error_log('[AstraeaOS] [FATAL] Logger serialization fault: ' . $e->getMessage());
        }
    }

    public static function debug(string $msg, array $ctx = []): void {
        self::log(self::LEVEL_DEBUG, $msg, $ctx);
    }

    public static function info(string $msg, array $ctx = []): void {
        self::log(self::LEVEL_INFO, $msg, $ctx);
    }

    public static function notice(string $msg, array $ctx = []): void {
        self::log(self::LEVEL_NOTICE, $msg, $ctx);
    }

    public static function warning(string $msg, array $ctx = []): void {
        self::log(self::LEVEL_WARNING, $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void {
        self::log(self::LEVEL_ERROR, $msg, $ctx);
    }

    public static function security(string $msg, array $ctx = []): void {
        self::log(self::LEVEL_SECURITY, $msg, $ctx);
    }

    public static function critical(string $msg, array $ctx = []): void {
        self::log(self::LEVEL_CRITICAL, $msg, $ctx);
    }

    /**
     * Deep-redact sensitive key-value pairs from arrays.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function redact(mixed $data): mixed {
        if (!is_array($data)) {
            return $data;
        }

        $clean = [];
        foreach ($data as $key => $value) {
            $keyLower = is_string($key) ? strtolower($key) : '';
            $isSensitive = false;

            foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
                if (str_contains($keyLower, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $clean[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $clean[$key] = self::redact($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Sanitize URI by removing or redacting sensitive parameters from the query string.
     *
     * @param string|null $rawUri
     * @return string
     */
    public static function sanitizeUri(?string $rawUri): string {
        if ($rawUri === null || $rawUri === '') {
            return 'cli';
        }

        $parts = parse_url($rawUri);
        $path = $parts['path'] ?? '/';

        if (empty($parts['query'])) {
            return $path;
        }

        parse_str($parts['query'], $queryParams);
        /** @var array<string, mixed> $queryParams */
        $redactedParams = self::redact($queryParams);

        return $path . '?' . http_build_query($redactedParams);
    }
}
