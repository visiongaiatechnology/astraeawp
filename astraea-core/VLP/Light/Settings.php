<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\VLP\Light;

final class Settings {
    public const VERSION = '1.0.1-alpha';
    public const OPTION_ENABLED = 'astraea_vlp_light_enabled';
    public const OPTION_POLICY_VERSION = 'astraea_vlp_policy_version';
    public const OPTION_SERVICES = 'astraea_vlp_services';
    public const OPTION_BANNER_TITLE = 'astraea_vlp_banner_title';
    public const OPTION_BANNER_TEXT = 'astraea_vlp_banner_text';
    public const OPTION_DATTRACK = 'astraea_vlp_dattrack_enabled';
    public const OPTION_STRICT_CACHE = 'astraea_vlp_strict_cache';
    public const COOKIE_NAME = 'astraea_vlp_consent';
    public const CONSENT_TTL = 15552000; // 180 days.
    public const OPTION_PRIVACY_URL = 'astraea_vlp_privacy_url';
    public const CATEGORIES = ['necessary', 'functional', 'statistics', 'marketing', 'external_media'];

    public static function isEnabled(): bool { return (bool)get_option(self::OPTION_ENABLED, true); }
    public static function dattrackEnabled(): bool { return (bool)get_option(self::OPTION_DATTRACK, true); }
    public static function strictCache(): bool { return (bool)get_option(self::OPTION_STRICT_CACHE, true); }
    public static function policyVersion(): int { return max(1, (int)get_option(self::OPTION_POLICY_VERSION, 1)); }
    public static function title(): string {
        $default = function_exists('astraea_t') ? astraea_t('vlp_banner_title') : 'Privatsphäre-Einstellungen';
        return (string)get_option(self::OPTION_BANNER_TITLE, $default);
    }
    public static function text(): string {
        $default = function_exists('astraea_t') ? astraea_t('vlp_banner_text') : 'Wir blockieren optionale Dienste, bis du sie ausdrücklich freigibst. Du kannst deine Auswahl jederzeit ändern.';
        return (string)get_option(self::OPTION_BANNER_TEXT, $default);
    }
    public static function privacyUrl(): string {
        $url = (string)get_option(self::OPTION_PRIVACY_URL, '');
        if ($url !== '') {
            return $url;
        }
        return function_exists('get_privacy_policy_url') ? (string)get_privacy_policy_url() : '';
    }

    public static function bumpPolicyVersion(): int {
        $next = self::policyVersion() + 1;
        update_option(self::OPTION_POLICY_VERSION, $next, false);
        return $next;
    }
}
