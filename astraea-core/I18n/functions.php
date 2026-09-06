<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

use Astraea\I18n\I18n;

/**
 * ============================================================================
 * AstraeaOS WP — Procedural I18n & Translation Helpers
 * ============================================================================
 * Global helper functions for zero-latency, in-memory localization across
 * the 9 authorized AstraeaOS languages.
 */

if (!function_exists('astraea_t')) {
    /**
     * Translate a string into the active or specified locale.
     *
     * @param string $text Source text string or dictionary key.
     * @param array<string|int, mixed>|string $args Optional substitution arguments (vsprintf or named placeholders).
     * @param string|null $locale Optional target locale override.
     * @return string Translated string.
     */
    function astraea_t(string $text, array|string $args = [], ?string $locale = null): string {
        $parsedArgs = is_array($args) ? $args : (strlen($args) > 0 ? [$args] : []);
        return I18n::translate($text, $parsedArgs, $locale);
    }
}

if (!function_exists('astraea_esc_t')) {
    /**
     * Translate and HTML-escape a string.
     *
     * @param string $text Source text string or dictionary key.
     * @param array<string|int, mixed>|string $args Optional substitution arguments.
     * @param string|null $locale Optional target locale override.
     * @return string Translated and escaped string.
     */
    function astraea_esc_t(string $text, array|string $args = [], ?string $locale = null): string {
        return esc_html(astraea_t($text, $args, $locale));
    }
}

if (!function_exists('astraea_has_t')) {
    /**
     * Check whether a translation key exists in the active dictionary or fallback.
     *
     * @param string $text Source text string or dictionary key.
     * @param string|null $locale Optional target locale override.
     * @return bool True if key exists.
     */
    function astraea_has_t(string $text, ?string $locale = null): bool {
        return I18n::has($text, $locale);
    }
}

if (!function_exists('astraea_lang_switcher')) {
    /**
     * Render the cyber-glass language switcher UI.
     *
     * @return string HTML markup.
     */
    function astraea_lang_switcher(): string {
        return I18n::renderSwitcher();
    }
}
