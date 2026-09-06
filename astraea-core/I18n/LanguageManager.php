<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\I18n;

if (!defined('ABSPATH')) {
    exit('Access Denied');
}

/**
 * WordPress Language Gatekeeper & Restriction Engine.
 *
 * Enforces the AstraeaOS Sovereign Language Policy:
 * Strictly deactivates and filters out all WordPress languages outside the
 * 9 authorized language targets (de_DE, en_US, ru_RU, es_ES, it_IT, ja, zh_CN, tr_TR, ar).
 *
 * Intercepts:
 * - get_available_languages
 * - translations_api_result
 * - pre_site_transient_available_translations
 * - wp_dropdown_languages
 * - locale
 * - core translation updates
 *
 * @package Astraea\I18n
 */
final class LanguageManager {

    private static bool $initialized = false;

    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        if (function_exists('add_filter')) {
            // 1. Filter local installed languages
            add_filter('get_available_languages', [self::class, 'filterAvailableLanguages'], 9999, 2);

            // 2. Filter remote translation API results (WordPress.org translation API)
            add_filter('translations_api_result', [self::class, 'filterTranslationsApiResult'], 9999, 3);

            // 3. Filter site transient for available translations
            add_filter('pre_site_transient_available_translations', [self::class, 'filterAvailableTranslationsTransient'], 9999);
            add_filter('site_transient_available_translations', [self::class, 'filterAvailableTranslationsTransient'], 9999);

            // 4. Filter language dropdown markup and arguments
            add_filter('wp_dropdown_languages', [self::class, 'filterDropdownLanguagesMarkup'], 9999);

            // 5. Enforce strict locale boundaries
            add_filter('locale', [self::class, 'enforceLocale'], 9999);
            add_filter('determine_locale', [self::class, 'enforceLocale'], 9999);

            // 6. Block translation updates for non-authorized languages
            add_filter('pre_set_site_transient_update_core', [self::class, 'filterCoreUpdateTranslations'], 9999);
            add_filter('site_transient_update_core', [self::class, 'filterCoreUpdateTranslations'], 9999);
        }

        self::$initialized = true;
    }

    /**
     * Filter the list of available languages to ONLY include authorized AstraeaOS targets.
     *
     * @param string[] $languages Array of available language codes.
     * @param string|null $dir Search directory.
     * @return string[] Whitelisted languages.
     */
    public static function filterAvailableLanguages(array $languages, ?string $dir = null): array {
        $allowed = array_keys(I18n::SUPPORTED_LANGUAGES);
        $filtered = array_values(array_intersect($languages, $allowed));

        // Always ensure en_US and de_DE are considered structurally available
        if (!in_array('de_DE', $filtered, true)) {
            $filtered[] = 'de_DE';
        }

        return array_unique($filtered);
    }

    /**
     * Filter WordPress.org Translation Installation API results.
     * Completely purges all 90+ non-authorized languages from remote fetch.
     *
     * @param array|\WP_Error $result API response array.
     * @param string $type Translation type (core, plugins, themes).
     * @param object|array $args API arguments.
     * @return array|\WP_Error Filtered API response.
     */
    public static function filterTranslationsApiResult(mixed $result, string $type, mixed $args): mixed {
        if (!is_array($result) || !isset($result['translations']) || !is_array($result['translations'])) {
            return $result;
        }

        $allowed = array_keys(I18n::SUPPORTED_LANGUAGES);
        $sanitized = [];

        foreach ($result['translations'] as $item) {
            if (isset($item['language']) && in_array($item['language'], $allowed, true)) {
                $sanitized[] = $item;
            }
        }

        $result['translations'] = $sanitized;
        return $result;
    }

    /**
     * Filter the cached available translations site transient.
     *
     * @param mixed $translations
     * @return mixed Filtered translations array.
     */
    public static function filterAvailableTranslationsTransient(mixed $translations): mixed {
        if (!is_array($translations)) {
            return $translations;
        }

        $allowed = array_keys(I18n::SUPPORTED_LANGUAGES);
        $filtered = [];

        foreach ($translations as $langKey => $data) {
            if (in_array($langKey, $allowed, true)) {
                $filtered[$langKey] = $data;
            }
        }

        return $filtered;
    }

    /**
     * Sanitize generated HTML markup for language dropdowns if needed.
     */
    public static function filterDropdownLanguagesMarkup(string $html): string {
        return $html;
    }

    /**
     * Strict locale firewall: If WordPress or a plugin passes an unauthorized locale,
     * deterministically sanitize to default (de_DE or en_US).
     *
     * @param string $locale
     * @return string Whitelisted locale.
     */
    public static function enforceLocale(string $locale): string {
        $normalized = I18n::normalizeLocale($locale);
        if (isset(I18n::SUPPORTED_LANGUAGES[$normalized])) {
            return $normalized;
        }

        return I18n::DEFAULT_LOCALE;
    }

    /**
     * Filter core update translation payloads to prevent downloading unapproved language packs.
     *
     * @param mixed $value Transient value.
     * @return mixed Filtered transient value.
     */
    public static function filterCoreUpdateTranslations(mixed $value): mixed {
        if (!is_object($value) || !isset($value->translations) || !is_array($value->translations)) {
            return $value;
        }

        $allowed = array_keys(I18n::SUPPORTED_LANGUAGES);
        $filtered = [];

        foreach ($value->translations as $trans) {
            $lang = is_array($trans) ? ($trans['language'] ?? '') : ($trans->language ?? '');
            if (in_array($lang, $allowed, true)) {
                $filtered[] = $trans;
            }
        }

        $value->translations = $filtered;
        return $value;
    }
}
