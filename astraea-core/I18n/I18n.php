<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\I18n;

if (!defined('ABSPATH')) {
    exit('Access Denied');
}

/**
 * AstraeaOS Core Internationalization & Localization Kernel.
 *
 * Implements high-throughput O(1) in-memory localization across 9 approved
 * language targets with native RTL directionality and WordPress gettext integration.
 *
 * Supported Targets:
 * - de_DE (Deutsch - Default)
 * - en_US (English US)
 * - ru_RU (Русский)
 * - es_ES (Español)
 * - it_IT (Italiano)
 * - ja (日本語)
 * - zh_CN (简体中文)
 * - tr_TR (Türkçe)
 * - ar (العربية - RTL)
 *
 * @package Astraea\I18n
 */
final class I18n {

    public const DEFAULT_LOCALE = 'de_DE';
    public const COOKIE_NAME = 'astraea_lang';

    /**
     * Complete specification matrix of the 9 authorized AstraeaOS languages.
     */
    public const SUPPORTED_LANGUAGES = [
        'de_DE' => [
            'code'      => 'de_DE',
            'short'     => 'de',
            'name'      => 'German',
            'native'    => 'Deutsch',
            'flag'      => '🇩🇪',
            'direction' => 'ltr',
            'iso'       => ['de', 'deu', 'ger'],
        ],
        'en_US' => [
            'code'      => 'en_US',
            'short'     => 'en',
            'name'      => 'English (US)',
            'native'    => 'English (US)',
            'flag'      => '🇺🇸',
            'direction' => 'ltr',
            'iso'       => ['en', 'eng'],
        ],
        'ru_RU' => [
            'code'      => 'ru_RU',
            'short'     => 'ru',
            'name'      => 'Russian',
            'native'    => 'Русский',
            'flag'      => '🇷🇺',
            'direction' => 'ltr',
            'iso'       => ['ru', 'rus'],
        ],
        'es_ES' => [
            'code'      => 'es_ES',
            'short'     => 'es',
            'name'      => 'Spanish',
            'native'    => 'Español',
            'flag'      => '🇪🇸',
            'direction' => 'ltr',
            'iso'       => ['es', 'spa'],
        ],
        'it_IT' => [
            'code'      => 'it_IT',
            'short'     => 'it',
            'name'      => 'Italian',
            'native'    => 'Italiano',
            'flag'      => '🇮🇹',
            'direction' => 'ltr',
            'iso'       => ['it', 'ita'],
        ],
        'ja' => [
            'code'      => 'ja',
            'short'     => 'ja',
            'name'      => 'Japanese',
            'native'    => '日本語',
            'flag'      => '🇯🇵',
            'direction' => 'ltr',
            'iso'       => ['ja', 'jpn'],
        ],
        'zh_CN' => [
            'code'      => 'zh_CN',
            'short'     => 'zh',
            'name'      => 'Chinese (China)',
            'native'    => '简体中文',
            'flag'      => '🇨🇳',
            'direction' => 'ltr',
            'iso'       => ['zh', 'zho', 'chi'],
        ],
        'tr_TR' => [
            'code'      => 'tr_TR',
            'short'     => 'tr',
            'name'      => 'Turkish',
            'native'    => 'Türkçe',
            'flag'      => '🇹🇷',
            'direction' => 'ltr',
            'iso'       => ['tr', 'tur'],
        ],
        'ar' => [
            'code'      => 'ar',
            'short'     => 'ar',
            'name'      => 'Arabic',
            'native'    => 'العربية',
            'flag'      => '🇸🇦',
            'direction' => 'rtl',
            'iso'       => ['ar', 'ara'],
        ],
    ];

    public const APPROVED_LANGUAGES = self::SUPPORTED_LANGUAGES;

    /** @var array<string, array<string, string>> In-memory cached language dictionaries */
    private static array $dictionaries = [];

    /** @var string|null Active locale cache */
    private static ?string $currentLocale = null;

    private static bool $initialized = false;
    private static bool $resolvingLocale = false;

    public static function reset(): void {
        self::$currentLocale = null;
        self::$resolvingLocale = false;
    }

    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        // 1. Process explicit language switch request via URL
        if (isset($_GET['astraea_lang']) || isset($_GET['lang'])) {
            $raw = (string)($_GET['astraea_lang'] ?? $_GET['lang'] ?? '');
            $normalized = self::normalizeLocale($raw);
            if (isset(self::SUPPORTED_LANGUAGES[$normalized])) {
                self::setLocale($normalized);
            }
        }

        // 2. Attach to WordPress gettext filter pipeline for Astraea textdomains
        if (function_exists('add_filter')) {
            add_filter('gettext', [self::class, 'filterGettext'], 20, 3);
            add_filter('gettext_with_context', [self::class, 'filterGettextWithContext'], 20, 4);
            add_filter('ngettext', [self::class, 'filterNgettext'], 20, 5);
            add_filter('locale', [self::class, 'filterLocale'], 20);
            add_filter('pre_determine_locale', [self::class, 'filterPreDetermineLocale'], 20);
            add_filter('is_rtl', [self::class, 'filterIsRtl'], 20);
        }

        // 3. Bind user preference synchronization once user context is verified
        if (function_exists('add_action')) {
            add_action('init', [self::class, 'syncUserLocale']);
        }

        self::$initialized = true;
    }

    /**
     * Resolve active locale based on hierarchy: Request -> Cookie -> User Profile -> Filter Candidate -> Site Option -> Default.
     *
     * Non-recursive: strictly avoids calling get_locale() or apply_filters('locale') to eliminate
     * call-stack memory exhaustion.
     *
     * @param string|null $candidate Optional candidate passed from WordPress filter hook.
     * @return string Canonical active locale.
     */
    public static function getLocale(?string $candidate = null): string {
        if (self::$currentLocale !== null) {
            return self::$currentLocale;
        }

        if (self::$resolvingLocale) {
            if ($candidate !== null && $candidate !== '') {
                $norm = self::normalizeLocale($candidate);
                if (isset(self::SUPPORTED_LANGUAGES[$norm])) {
                    return $norm;
                }
            }
            return self::DEFAULT_LOCALE;
        }

        self::$resolvingLocale = true;
        try {
            // 1. Explicit URL query override (?astraea_lang= or ?lang=)
            if (isset($_GET['astraea_lang']) || isset($_GET['lang'])) {
                $raw = (string)($_GET['astraea_lang'] ?? $_GET['lang'] ?? '');
                $normalized = self::normalizeLocale($raw);
                if (isset(self::SUPPORTED_LANGUAGES[$normalized])) {
                    self::$currentLocale = $normalized;
                    return self::$currentLocale;
                }
            }

            // 2. Cookie Preference
            if (isset($_COOKIE[self::COOKIE_NAME])) {
                $cookieLocale = self::normalizeLocale((string)$_COOKIE[self::COOKIE_NAME]);
                if (isset(self::SUPPORTED_LANGUAGES[$cookieLocale])) {
                    self::$currentLocale = $cookieLocale;
                    return self::$currentLocale;
                }
            }

            // 3. Authenticated User Profile Preference (only when user subsystem is loaded)
            if (function_exists('did_action') && did_action('init') > 0 && function_exists('is_user_logged_in') && function_exists('get_current_user_id')) {
                if (is_user_logged_in()) {
                    $userId = (int)get_current_user_id();
                    if ($userId > 0 && function_exists('get_user_meta')) {
                        $userLocale = get_user_meta($userId, 'locale', true);
                        if (is_string($userLocale) && $userLocale !== '') {
                            $norm = self::normalizeLocale($userLocale);
                            if (isset(self::SUPPORTED_LANGUAGES[$norm])) {
                                self::$currentLocale = $norm;
                                return self::$currentLocale;
                            }
                        }
                    }
                }
            }

            // 4. Candidate passed from WordPress filter hook
            if ($candidate !== null && $candidate !== '') {
                $norm = self::normalizeLocale($candidate);
                if (isset(self::SUPPORTED_LANGUAGES[$norm])) {
                    self::$currentLocale = $norm;
                    return self::$currentLocale;
                }
            }

            // 5. Global WordPress state (Direct lookup without triggering get_locale() filter recursion)
            if (!empty($GLOBALS['locale']) && is_string($GLOBALS['locale'])) {
                $norm = self::normalizeLocale($GLOBALS['locale']);
                if (isset(self::SUPPORTED_LANGUAGES[$norm])) {
                    self::$currentLocale = $norm;
                    return self::$currentLocale;
                }
            }
            if (!empty($GLOBALS['wp_local_package']) && is_string($GLOBALS['wp_local_package'])) {
                $norm = self::normalizeLocale($GLOBALS['wp_local_package']);
                if (isset(self::SUPPORTED_LANGUAGES[$norm])) {
                    self::$currentLocale = $norm;
                    return self::$currentLocale;
                }
            }

            // 6. Direct WPLANG config or site option (Direct lookup without get_locale() call)
            if (defined('WPLANG') && is_string(WPLANG) && WPLANG !== '') {
                $norm = self::normalizeLocale(WPLANG);
                if (isset(self::SUPPORTED_LANGUAGES[$norm])) {
                    self::$currentLocale = $norm;
                    return self::$currentLocale;
                }
            }
            if (function_exists('get_option')) {
                $siteLocale = get_option('WPLANG');
                if (is_string($siteLocale) && $siteLocale !== '') {
                    $norm = self::normalizeLocale($siteLocale);
                    if (isset(self::SUPPORTED_LANGUAGES[$norm])) {
                        self::$currentLocale = $norm;
                        return self::$currentLocale;
                    }
                }
            }

            // 7. Sovereign Default: Deutsch (de_DE)
            self::$currentLocale = self::DEFAULT_LOCALE;
            return self::$currentLocale;
        } finally {
            self::$resolvingLocale = false;
        }
    }

    /**
     * Explicitly set the active runtime locale and persist via cookie.
     */
    public static function setLocale(string $locale): void {
        $normalized = self::normalizeLocale($locale);
        if (!isset(self::SUPPORTED_LANGUAGES[$normalized])) {
            return;
        }

        self::$currentLocale = $normalized;

        if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('get_current_user_id')) {
            $userId = (int)get_current_user_id();
            if ($userId > 0) {
                update_user_meta($userId, 'locale', $normalized);
            }
        }

        if (!headers_sent()) {
            $cookiePath = defined('COOKIEPATH') && is_string(COOKIEPATH) ? COOKIEPATH : '/';
            $cookieDomain = defined('COOKIE_DOMAIN') && is_string(COOKIE_DOMAIN) ? COOKIE_DOMAIN : '';
            $secure = is_ssl();
            setcookie(self::COOKIE_NAME, $normalized, [
                'expires'  => time() + 31536000,
                'path'     => $cookiePath,
                'domain'   => $cookieDomain,
                'secure'   => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
    }

    public static function syncUserLocale(): void {
        if (isset($_GET['astraea_lang']) || isset($_GET['lang'])) {
            $raw = (string)($_GET['astraea_lang'] ?? $_GET['lang'] ?? '');
            $normalized = self::normalizeLocale($raw);
            if (isset(self::SUPPORTED_LANGUAGES[$normalized])) {
                self::setLocale($normalized);
            }
        }
    }

    /**
     * Normalize incoming language codes (e.g. 'de', 'de-de', 'de_DE', 'german') to canonical keys.
     */
    public static function normalizeLocale(string $raw): string {
        $clean = trim($raw);
        if ($clean === '') {
            return self::DEFAULT_LOCALE;
        }

        // Direct key check
        if (isset(self::SUPPORTED_LANGUAGES[$clean])) {
            return $clean;
        }

        $lower = strtolower($clean);
        $mapped = str_replace('-', '_', $lower);

        foreach (self::SUPPORTED_LANGUAGES as $key => $meta) {
            if ($mapped === strtolower($key) || $lower === $meta['short']) {
                return $key;
            }
            if (in_array($lower, $meta['iso'], true)) {
                return $key;
            }
        }

        // Short prefixes (e.g. 'ru-MO' -> 'ru_RU')
        $parts = explode('_', $mapped);
        $prefix = $parts[0] ?? '';
        foreach (self::SUPPORTED_LANGUAGES as $key => $meta) {
            if ($prefix === $meta['short']) {
                return $key;
            }
        }

        return self::DEFAULT_LOCALE;
    }

    /**
     * Check if a locale is right-to-left (Arabic).
     */
    public static function isRtl(?string $locale = null): bool {
        $loc = $locale !== null ? self::normalizeLocale($locale) : self::getLocale();
        return (self::SUPPORTED_LANGUAGES[$loc]['direction'] ?? 'ltr') === 'rtl';
    }

    /**
     * Load the dedicated language dictionary file into memory.
     *
     * @param string $locale Canonical locale key (e.g. 'de_DE', 'en_US', 'ar')
     * @return array<string, string>
     */
    public static function getDictionary(string $locale): array {
        $norm = self::normalizeLocale($locale);

        if (!isset(self::$dictionaries[$norm])) {
            $file = __DIR__ . '/languages/' . $norm . '.php';
            if (is_file($file)) {
                $dict = include $file;
                self::$dictionaries[$norm] = is_array($dict) ? $dict : [];
            } else {
                self::$dictionaries[$norm] = [];
            }
        }

        return self::$dictionaries[$norm];
    }

    /**
     * Translate a string into the active or requested locale.
     *
     * @param string $text Source text string.
     * @param array<string|int, mixed> $args Optional substitution arguments (vsprintf or named placeholders).
     * @param string|null $locale Optional target locale override.
     * @return string Translated and formatted string.
     */
    public static function translate(string $text, array $args = [], ?string $locale = null): string {
        $loc = $locale !== null ? self::normalizeLocale($locale) : self::getLocale();
        $dict = self::getDictionary($loc);
        $key = trim($text);

        $translation = null;

        // 1. Direct key match
        if (isset($dict[$key])) {
            $translation = $dict[$key];
        } elseif (isset($dict[$text])) {
            $translation = $dict[$text];
        } else {
            // 2. Case-insensitive lookup fallback
            foreach ($dict as $k => $v) {
                if (strcasecmp($k, $key) === 0) {
                    $translation = $v;
                    break;
                }
            }
        }

        // 3. Fallback to English (en_US) dictionary if translation missing in target language
        if ($translation === null && $loc !== 'en_US') {
            $enDict = self::getDictionary('en_US');
            if (isset($enDict[$key])) {
                $translation = $enDict[$key];
            } elseif (isset($enDict[$text])) {
                $translation = $enDict[$text];
            }
        }

        // 4. Ultimate fallback to raw source text
        $result = $translation ?? $text;

        // 5. Interpolation
        if (!empty($args)) {
            // Named interpolation: {name}, %name%
            foreach ($args as $k => $v) {
                if (is_string($k) && (is_scalar($v) || (is_object($v) && method_exists($v, '__toString')))) {
                    $result = str_replace(['{' . $k . '}', '%' . $k . '%'], (string)$v, $result);
                }
            }
            // Positional printf interpolation if %s or %d markers remain
            if (preg_match('/%[0-9]*\$?[sdfxXo]/', $result)) {
                $numericArgs = array_values(array_filter($args, 'is_numeric', ARRAY_FILTER_USE_KEY));
                if (!empty($numericArgs)) {
                    $result = @vsprintf($result, $numericArgs) ?: $result;
                }
            }
        }

        return $result;
    }

    /**
     * Alias shorthand for translation.
     */
    public static function t(string $text, array $args = [], ?string $locale = null): string {
        return self::translate($text, $args, $locale);
    }

    /**
     * Check whether a translation key exists in the active dictionary (or English fallback).
     *
     * @param string $text Translation key or source text string.
     * @param string|null $locale Optional target locale override.
     * @return bool True if translation key exists.
     */
    public static function has(string $text, ?string $locale = null): bool {
        $loc = $locale !== null ? self::normalizeLocale($locale) : self::getLocale();
        $dict = self::getDictionary($loc);
        $key = trim($text);

        if (isset($dict[$key]) || isset($dict[$text])) {
            return true;
        }

        foreach ($dict as $k => $v) {
            if (strcasecmp($k, $key) === 0) {
                return true;
            }
        }

        if ($loc !== 'en_US') {
            $enDict = self::getDictionary('en_US');
            if (isset($enDict[$key]) || isset($enDict[$text])) {
                return true;
            }
            foreach ($enDict as $k => $v) {
                if (strcasecmp($k, $key) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Intercept WordPress gettext() calls for Astraea text domains.
     */
    public static function filterGettext(string $translation, string $text, string $domain): string {
        if (!in_array($domain, ['astraea', 'astraeaos', 'astraea-core', 'vgt-sentinel', 'default'], true)) {
            return $translation;
        }

        // Only translate default domain if it matches our dictionary keys to avoid breaking generic WP strings
        if ($domain === 'default') {
            $activeLoc = self::getLocale();
            $dict = self::getDictionary($activeLoc);
            $trimmed = trim($text);
            if (isset($dict[$trimmed]) || isset($dict[$text])) {
                return self::translate($text);
            }
            return $translation;
        }

        return self::translate($text);
    }

    public static function filterGettextWithContext(string $translation, string $text, string $context, string $domain): string {
        if (!in_array($domain, ['astraea', 'astraeaos', 'astraea-core', 'vgt-sentinel'], true)) {
            return $translation;
        }

        $contextKey = $context . "\x04" . $text;
        $activeLoc = self::getLocale();
        $dict = self::getDictionary($activeLoc);

        if (isset($dict[$contextKey])) {
            return $dict[$contextKey];
        }

        return self::translate($text);
    }

    public static function filterNgettext(string $translation, string $single, string $plural, int $number, string $domain): string {
        if (!in_array($domain, ['astraea', 'astraeaos', 'astraea-core', 'vgt-sentinel'], true)) {
            return $translation;
        }

        $key = ($number === 1) ? $single : $plural;
        return self::translate($key, ['count' => $number, 1 => $number]);
    }

    public static function filterLocale(string $locale): string {
        return self::getLocale($locale);
    }

    public static function filterPreDetermineLocale(?string $locale = null): ?string {
        if ($locale !== null && $locale !== '') {
            return self::normalizeLocale($locale);
        }
        return self::getLocale();
    }

    public static function filterIsRtl(bool $isRtl): bool {
        return self::isRtl();
    }

    /**
     * Render a Cyber-Glass multilingual switcher component.
     *
     * @param string $class Additional CSS classes
     * @param bool $showFlags Whether to render flags
     * @return string HTML markup
     */
    public static function renderSwitcher(string $class = '', bool $showFlags = true): string {
        $current = self::getLocale();
        $baseUrl = function_exists('remove_query_arg')
            ? (string)remove_query_arg(['astraea_lang', 'lang', 'vis_lang'])
            : (string)($_SERVER['REQUEST_URI'] ?? '');

        $html = '<div class="astraea-lang-selector astraea-lang-matrix ' . esc_attr($class) . '" style="display: inline-flex; align-items: center; gap: 4px; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 20px; padding: 3px 6px; font-size: 11px; font-weight: 700; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">';

        foreach (self::SUPPORTED_LANGUAGES as $code => $meta) {
            $isActive = ($current === $code);
            $url = function_exists('add_query_arg')
                ? (string)add_query_arg('astraea_lang', $code, $baseUrl)
                : '?astraea_lang=' . urlencode($code);
            $style = $isActive
                ? 'background: rgba(14, 165, 233, 0.25); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.5); font-weight: 700;'
                : 'color: #94a3b8; border: 1px solid transparent; opacity: 0.8;';

            $label = ($showFlags ? $meta['flag'] . ' ' : '') . strtoupper($meta['short']);

            $html .= sprintf(
                '<a href="%s" title="%s (%s)" style="text-decoration: none; padding: 2px 7px; border-radius: 12px; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 3px; %s">%s</a>',
                esc_url($url),
                esc_attr($meta['native']),
                esc_attr($meta['name']),
                $style,
                esc_html($label)
            );
        }

        $html .= '</div>';
        return $html;
    }
}

require_once __DIR__ . '/functions.php';
