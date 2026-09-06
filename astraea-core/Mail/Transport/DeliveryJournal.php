<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Transport;

use Astraea\Mail\ProviderRegistry;
use Astraea\Mail\Settings;
use Astraea\Mail\SmtpConfig;
use Astraea\Mail\Store\EncryptedRecordStore;

final class DeliveryJournal
{
    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 30): array
    {
        return array_slice(EncryptedRecordStore::loadList(Settings::JOURNAL_OPTION, Settings::JOURNAL_AAD), 0, max(1, min($limit, Settings::JOURNAL_LIMIT)));
    }

    public static function record(string $status, SmtpConfig $config, int $recipientCount = 0, string $code = '', string $summary = ''): void
    {
        $status = in_array($status, ['sent', 'failed', 'blocked'], true) ? $status : 'failed';
        $entry = [
            'id' => bin2hex(random_bytes(8)),
            'timestamp' => time(),
            'datetime' => gmdate('c'),
            'status' => $status,
            'provider' => $config->provider,
            'provider_label' => (string)(ProviderRegistry::get($config->provider)['label'] ?? $config->provider),
            'encryption' => $config->encryption,
            'recipients' => max(0, min(10000, $recipientCount)),
            'code' => self::token($code, 64),
            'summary' => self::sanitizeSummary($summary),
        ];
        self::withLock(static function () use ($entry): void {
            $records = EncryptedRecordStore::loadList(Settings::JOURNAL_OPTION, Settings::JOURNAL_AAD);
            array_unshift($records, $entry);
            EncryptedRecordStore::saveList(Settings::JOURNAL_OPTION, Settings::JOURNAL_AAD, array_slice($records, 0, Settings::JOURNAL_LIMIT));
        });
    }

    public static function clear(): void
    {
        delete_option(Settings::JOURNAL_OPTION);
    }

    public static function circuitBreakerOpen(): bool
    {
        return get_transient('astraea_mail_breaker') !== false;
    }

    public static function recordFailure(): void
    {
        $count = (int)get_transient('astraea_mail_failure_count');
        $count++;
        set_transient('astraea_mail_failure_count', $count, Settings::CIRCUIT_BREAKER_WINDOW);
        if ($count >= Settings::CIRCUIT_BREAKER_THRESHOLD) {
            set_transient('astraea_mail_breaker', 1, Settings::CIRCUIT_BREAKER_COOLDOWN);
        }
    }

    public static function recordSuccess(): void
    {
        delete_transient('astraea_mail_failure_count');
        delete_transient('astraea_mail_breaker');
    }

    public static function resetBreaker(): void
    {
        self::recordSuccess();
    }

    private static function withLock(callable $callback): void
    {
        $key = 'astraea_mail_journal_lock';
        $token = bin2hex(random_bytes(12));
        $owned = false;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (add_option($key, ['token' => $token, 'time' => microtime(true)], '', false)) {
                $owned = true;
                break;
            }
            $existing = get_option($key, []);
            if (is_array($existing) && isset($existing['time']) && is_numeric($existing['time']) && microtime(true) - (float)$existing['time'] > 5.0) {
                delete_option($key);
                continue;
            }
            usleep(20000 * ($attempt + 1));
        }
        if (!$owned) {
            return;
        }
        try {
            $callback();
        } finally {
            $existing = get_option($key, []);
            if (is_array($existing) && isset($existing['token']) && is_string($existing['token']) && hash_equals($token, $existing['token'])) {
                delete_option($key);
            }
        }
    }

    private static function token(string $value, int $max): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', trim($value)) ?? '';
        return substr($value, 0, $max);
    }

    private static function sanitizeSummary(string $summary): string
    {
        $summary = preg_replace('/[\x00-\x1F\x7F]/', ' ', $summary) ?? '';
        $summary = preg_replace('/\b(password|passphrase|token|secret|api[_-]?key|authorization|credential)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $summary) ?? $summary;
        $summary = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $summary) ?? $summary;
        return substr(trim($summary), 0, 240);
    }
}
