<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Tests;

use Astraea\I18n\I18n;
use Astraea\I18n\LanguageManager;

/**
 * AstraeaOS Core I18n & Language Policy Test Suite.
 *
 * Verifies:
 * - 9-Language Whitelist & Specification Matrix
 * - Zero-loss Dictionary Loading across all 9 approved locales
 * - Fallback and printf / named placeholder interpolation
 * - RTL directionality for Arabic
 * - Strict WordPress Language Firewall (Deactivation of all non-whitelisted languages)
 */
final class I18nTest {

    public static function run(): void {
        echo "Running I18nTest...\n";

        I18n::init();
        LanguageManager::init();

        self::testApprovedLanguagesMatrix();
        self::testDictionaryLoadingAndCompleteness();
        self::testRtlDetection();
        self::testTranslationAndInterpolation();
        self::testLanguageManagerWhitelistEnforcement();
        self::testLanguageManagerApiFiltering();
        self::testLanguageManagerLocaleFirewall();
        self::testLocaleFilterRecursionImmunity();
        self::testProceduralHelpers();

        echo "  -> I18nTest completed.\n";
    }

    private static function testApprovedLanguagesMatrix(): void {
        $expectedLanguages = [
            'de_DE',
            'en_US',
            'ru_RU',
            'es_ES',
            'it_IT',
            'ja',
            'zh_CN',
            'tr_TR',
            'ar',
        ];

        TestCase::assertEquals(
            count($expectedLanguages),
            count(I18n::SUPPORTED_LANGUAGES),
            'Exactly 9 approved languages must be defined in I18n::SUPPORTED_LANGUAGES'
        );

        foreach ($expectedLanguages as $code) {
            TestCase::assertTrue(
                isset(I18n::SUPPORTED_LANGUAGES[$code]),
                "Language '{$code}' must be registered in SUPPORTED_LANGUAGES"
            );
            $meta = I18n::SUPPORTED_LANGUAGES[$code];
            TestCase::assertTrue(!empty($meta['name']), "Language '{$code}' must have an English name");
            TestCase::assertTrue(!empty($meta['native']), "Language '{$code}' must have a native name");
            TestCase::assertTrue(!empty($meta['flag']), "Language '{$code}' must have an emoji flag");
            TestCase::assertTrue(in_array($meta['direction'], ['ltr', 'rtl'], true), "Language '{$code}' direction must be ltr or rtl");
        }
    }

    private static function testDictionaryLoadingAndCompleteness(): void {
        $coreKeys = [
            'app_name',
            'system_title',
            'nav_dashboard',
            'nav_modules',
            'nav_security',
            'status_active',
            'status_disabled',
            'action_save',
            'vlp_banner_title',
            'vlp_accept_all',
            'mail_gateway_title',
            'perf_cache_title',
            'genesis_locked',
            'lang_restriction_notice',
        ];

        foreach (array_keys(I18n::SUPPORTED_LANGUAGES) as $locale) {
            $dict = I18n::getDictionary($locale);
            TestCase::assertTrue(!empty($dict), "Dictionary for '{$locale}' must not be empty");
            TestCase::assertTrue(isset($dict['__meta']), "Dictionary for '{$locale}' must have __meta entry");
            TestCase::assertEquals($locale, $dict['__meta']['locale'], "Meta locale must match requested '{$locale}'");

            foreach ($coreKeys as $key) {
                TestCase::assertTrue(
                    isset($dict[$key]) && is_string($dict[$key]) && strlen($dict[$key]) > 0,
                    "Dictionary for '{$locale}' must contain non-empty string for key '{$key}'"
                );
            }
        }
    }

    private static function testRtlDetection(): void {
        TestCase::assertTrue(I18n::isRtl('ar'), "Arabic ('ar') must be detected as RTL");
        TestCase::assertFalse(I18n::isRtl('de_DE'), "German ('de_DE') must be detected as LTR");
        TestCase::assertFalse(I18n::isRtl('en_US'), "English ('en_US') must be detected as LTR");
        TestCase::assertFalse(I18n::isRtl('ru_RU'), "Russian ('ru_RU') must be detected as LTR");
        TestCase::assertFalse(I18n::isRtl('ja'), "Japanese ('ja') must be detected as LTR");
        TestCase::assertFalse(I18n::isRtl('zh_CN'), "Chinese ('zh_CN') must be detected as LTR");
    }

    private static function testTranslationAndInterpolation(): void {
        // German
        $deTitle = I18n::translate('app_name', [], 'de_DE');
        TestCase::assertEquals('AstraeaOS Core', $deTitle, "de_DE translation for 'app_name'");

        // Arabic
        $arStatus = I18n::translate('status_active', [], 'ar');
        TestCase::assertEquals('نشط', $arStatus, "Arabic translation for 'status_active'");

        // Japanese
        $jaStatus = I18n::translate('status_active', [], 'ja');
        TestCase::assertEquals('有効', $jaStatus, "Japanese translation for 'status_active'");

        // Russian
        $ruStatus = I18n::translate('status_active', [], 'ru_RU');
        TestCase::assertEquals('Активен', $ruStatus, "Russian translation for 'status_active'");

        // Interpolation with sprintf positional arguments
        $interpolated = I18n::translate('admin_version', ['1.0.0', '7.1'], 'en_US');
        TestCase::assertStringContains('1.0.0', $interpolated, 'Positional argument %s must be interpolated');
        TestCase::assertStringContains('7.1', $interpolated, 'Second positional argument %s must be interpolated');

        // Fallback for nonexistent key
        $fallback = I18n::translate('nonexistent_key_xyz', [], 'en_US');
        TestCase::assertEquals('nonexistent_key_xyz', $fallback, 'Missing key must return original key string as fallback');
    }

    private static function testLanguageManagerWhitelistEnforcement(): void {
        $inputLanguages = [
            'de_DE',
            'en_US',
            'fr_FR', // Not approved
            'ru_RU',
            'pl_PL', // Not approved
            'es_ES',
            'it_IT',
            'ja',
            'zh_CN',
            'tr_TR',
            'ar',
            'pt_BR', // Not approved
            'nl_NL', // Not approved
            'sv_SE', // Not approved
        ];

        $filtered = LanguageManager::filterAvailableLanguages($inputLanguages);

        TestCase::assertEquals(9, count($filtered), 'LanguageManager must whitelist exactly 9 approved languages');
        TestCase::assertFalse(in_array('fr_FR', $filtered, true), 'fr_FR must be excluded');
        TestCase::assertFalse(in_array('pl_PL', $filtered, true), 'pl_PL must be excluded');
        TestCase::assertFalse(in_array('pt_BR', $filtered, true), 'pt_BR must be excluded');
        TestCase::assertFalse(in_array('nl_NL', $filtered, true), 'nl_NL must be excluded');
        TestCase::assertFalse(in_array('sv_SE', $filtered, true), 'sv_SE must be excluded');
        TestCase::assertTrue(in_array('de_DE', $filtered, true), 'de_DE must be present');
        TestCase::assertTrue(in_array('ar', $filtered, true), 'ar must be present');
        TestCase::assertTrue(in_array('zh_CN', $filtered, true), 'zh_CN must be present');
    }

    private static function testLanguageManagerApiFiltering(): void {
        $mockApiResponse = [
            'translations' => [
                ['language' => 'de_DE', 'version' => '7.1'],
                ['language' => 'fr_FR', 'version' => '7.1'], // unauthorized
                ['language' => 'ar',    'version' => '7.1'],
                ['language' => 'fi',    'version' => '7.1'], // unauthorized
                ['language' => 'ru_RU', 'version' => '7.1'],
            ],
        ];

        $result = LanguageManager::filterTranslationsApiResult($mockApiResponse, 'core', []);
        TestCase::assertTrue(is_array($result) && isset($result['translations']));
        TestCase::assertEquals(3, count($result['translations']), 'Only 3 whitelisted languages must survive API filter');

        $surviving = array_column($result['translations'], 'language');
        TestCase::assertTrue(in_array('de_DE', $surviving, true));
        TestCase::assertTrue(in_array('ar', $surviving, true));
        TestCase::assertTrue(in_array('ru_RU', $surviving, true));
        TestCase::assertFalse(in_array('fr_FR', $surviving, true));
        TestCase::assertFalse(in_array('fi', $surviving, true));
    }

    private static function testLanguageManagerLocaleFirewall(): void {
        TestCase::assertEquals('de_DE', LanguageManager::enforceLocale('de_DE'));
        TestCase::assertEquals('ar', LanguageManager::enforceLocale('ar'));
        TestCase::assertEquals('ja', LanguageManager::enforceLocale('ja'));
        // Non-approved locales must be clamped to DEFAULT_LOCALE (de_DE)
        TestCase::assertEquals(I18n::DEFAULT_LOCALE, LanguageManager::enforceLocale('fr_FR'));
        TestCase::assertEquals(I18n::DEFAULT_LOCALE, LanguageManager::enforceLocale('nl_NL'));
        TestCase::assertEquals(I18n::DEFAULT_LOCALE, LanguageManager::enforceLocale('ko_KR'));
    }

    private static function testLocaleFilterRecursionImmunity(): void {
        I18n::reset();

        // 1. Simulating WordPress get_locale() calling apply_filters('locale', $locale)
        // Must resolve without memory exhaustion, stack overflow or infinite recursion
        $filteredDe = apply_filters('locale', 'de_DE');
        TestCase::assertEquals('de_DE', $filteredDe, 'Locale filter must return de_DE without recursion');

        // 2. Supported candidate 'ar'
        I18n::reset();
        $filteredAr = apply_filters('locale', 'ar');
        TestCase::assertEquals('ar', $filteredAr, 'Locale filter must accept supported candidate ar');

        // 3. Pre-determine locale short-circuit hook
        I18n::reset();
        $preDetermined = apply_filters('pre_determine_locale', null);
        TestCase::assertEquals('de_DE', $preDetermined, 'pre_determine_locale filter must return valid active locale');

        // 4. Unauthorized locale must be clamped by language firewall
        I18n::reset();
        $filteredUnauthorized = apply_filters('locale', 'fr_FR');
        TestCase::assertEquals(I18n::DEFAULT_LOCALE, $filteredUnauthorized, 'Unauthorized locale must be clamped to default');

        // 5. Successive calls must remain stable
        for ($i = 0; $i < 100; $i++) {
            $rep = apply_filters('locale', 'de_DE');
            TestCase::assertEquals('de_DE', $rep, 'Successive locale filter calls must remain deterministic');
        }
    }

    private static function testProceduralHelpers(): void {
        TestCase::assertTrue(function_exists('astraea_t'), "Helper 'astraea_t' must be defined");
        TestCase::assertTrue(function_exists('astraea_esc_t'), "Helper 'astraea_esc_t' must be defined");
        TestCase::assertTrue(function_exists('astraea_has_t'), "Helper 'astraea_has_t' must be defined");
        TestCase::assertTrue(function_exists('astraea_lang_switcher'), "Helper 'astraea_lang_switcher' must be defined");

        // Test I18n::has and astraea_has_t
        TestCase::assertTrue(I18n::has('status_active'), "I18n::has must return true for existing key");
        TestCase::assertTrue(\astraea_has_t('status_active'), "astraea_has_t must return true for existing key");
        TestCase::assertFalse(I18n::has('nonexistent_key_12345'), "I18n::has must return false for nonexistent key");
        TestCase::assertFalse(\astraea_has_t('nonexistent_key_12345'), "astraea_has_t must return false for nonexistent key");

        $translated = \astraea_t('status_active', [], 'de_DE');
        TestCase::assertEquals('Aktiv', $translated, "astraea_t() must return 'Aktiv' for German");

        $switcherHtml = \astraea_lang_switcher();
        TestCase::assertStringContains('astraea-lang-selector', $switcherHtml, 'Language switcher markup must contain container class');
        TestCase::assertStringContains('de_DE', $switcherHtml, 'Language switcher markup must include de_DE');
        TestCase::assertStringContains('ar', $switcherHtml, 'Language switcher markup must include ar');
    }
}
