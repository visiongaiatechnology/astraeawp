<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail;

final class Settings
{
    public const VERSION = '1.0.0-alpha';
    public const CONFIG_OPTION = 'astraea_mail_config_v1';
    public const JOURNAL_OPTION = 'astraea_mail_journal_v1';
    public const PROBE_OPTION = 'astraea_mail_probe_v1';
    public const OAUTH_CACHE_OPTION = 'astraea_mail_oauth_cache_v1';
    public const CONFIG_AAD = 'astraea:mail:config:v1';
    public const JOURNAL_AAD = 'astraea:mail:journal:v1';
    public const PROBE_AAD = 'astraea:mail:probe:v1';
    public const OAUTH_CACHE_AAD = 'astraea:mail:oauth-cache:v1';
    public const TEST_NONCE = 'astraea_mail_test';
    public const SAVE_NONCE = 'astraea_mail_save';
    public const CLEAR_NONCE = 'astraea_mail_clear_journal';
    public const RESET_BREAKER_NONCE = 'astraea_mail_reset_breaker';
    public const TEST_RATE_LIMIT = 5;
    public const TEST_RATE_WINDOW = 900;
    public const CIRCUIT_BREAKER_THRESHOLD = 5;
    public const CIRCUIT_BREAKER_WINDOW = 600;
    public const CIRCUIT_BREAKER_COOLDOWN = 300;
    public const JOURNAL_LIMIT = 80;

    private function __construct() {}

    public static function externalPassword(): string
    {
        if (defined('ASTRAEA_SMTP_PASSWORD') && is_string(ASTRAEA_SMTP_PASSWORD) && ASTRAEA_SMTP_PASSWORD !== '') {
            return ASTRAEA_SMTP_PASSWORD;
        }
        $value = getenv('ASTRAEA_SMTP_PASSWORD');
        return is_string($value) ? $value : '';
    }

    public static function externalUsername(): string
    {
        if (defined('ASTRAEA_SMTP_USERNAME') && is_string(ASTRAEA_SMTP_USERNAME) && ASTRAEA_SMTP_USERNAME !== '') {
            return ASTRAEA_SMTP_USERNAME;
        }
        $value = getenv('ASTRAEA_SMTP_USERNAME');
        return is_string($value) ? $value : '';
    }

    public static function externalOAuthAccessToken(): string
    {
        if (defined('ASTRAEA_SMTP_OAUTH_ACCESS_TOKEN') && is_string(ASTRAEA_SMTP_OAUTH_ACCESS_TOKEN) && ASTRAEA_SMTP_OAUTH_ACCESS_TOKEN !== '') {
            return ASTRAEA_SMTP_OAUTH_ACCESS_TOKEN;
        }
        $value = getenv('ASTRAEA_SMTP_OAUTH_ACCESS_TOKEN');
        return is_string($value) ? $value : '';
    }

    public static function externalOAuthClientSecret(): string
    {
        if (defined('ASTRAEA_SMTP_OAUTH_CLIENT_SECRET') && is_string(ASTRAEA_SMTP_OAUTH_CLIENT_SECRET) && ASTRAEA_SMTP_OAUTH_CLIENT_SECRET !== '') {
            return ASTRAEA_SMTP_OAUTH_CLIENT_SECRET;
        }
        $value = getenv('ASTRAEA_SMTP_OAUTH_CLIENT_SECRET');
        return is_string($value) ? $value : '';
    }

    public static function externalOAuthRefreshToken(): string
    {
        if (defined('ASTRAEA_SMTP_OAUTH_REFRESH_TOKEN') && is_string(ASTRAEA_SMTP_OAUTH_REFRESH_TOKEN) && ASTRAEA_SMTP_OAUTH_REFRESH_TOKEN !== '') {
            return ASTRAEA_SMTP_OAUTH_REFRESH_TOKEN;
        }
        $value = getenv('ASTRAEA_SMTP_OAUTH_REFRESH_TOKEN');
        return is_string($value) ? $value : '';
    }

    public static function externalDkimPrivateKey(): string
    {
        if (defined('ASTRAEA_DKIM_PRIVATE_KEY') && is_string(ASTRAEA_DKIM_PRIVATE_KEY) && ASTRAEA_DKIM_PRIVATE_KEY !== '') {
            return ASTRAEA_DKIM_PRIVATE_KEY;
        }
        $value = getenv('ASTRAEA_DKIM_PRIVATE_KEY');
        return is_string($value) ? $value : '';
    }
}
