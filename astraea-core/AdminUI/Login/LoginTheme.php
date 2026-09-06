<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\AdminUI\Login;

/**
 * AstraeaOS Glass Login Theme.
 *
 * Transforms the WordPress authentication screen (/wp-login.php) into
 * an obsidian glass portal featuring:
 * - Official metallic cyan Astraea emblem
 * - Deep space background with ambient specular lighting
 * - Translucent Glass Card form with high-precision inputs
 * - VisionGaiaTechnology ecosystem branding
 * - Complete removal of legacy WordPress branding
 *
 * @package Astraea\AdminUI\Login
 */
final class LoginTheme {

    /**
     * Initialize login hooks.
     */
    public static function init(): void {
        add_action('login_enqueue_scripts', [self::class, 'enqueueStyles']);
        add_filter('login_headerurl', [self::class, 'filterHeaderUrl']);
        add_filter('login_headertext', [self::class, 'filterHeaderText']);
        add_filter('login_body_class', [self::class, 'filterBodyClass']);
        add_action('login_footer', [self::class, 'renderLoginFooter']);
    }

    /**
     * Enqueue local CSS assets for login screen.
     */
    public static function enqueueStyles(): void {
        $base = function_exists('site_url')
            ? site_url('/astraea-core/AdminUI/assets/css/')
            : content_url('../astraea-core/AdminUI/assets/css/');

        wp_enqueue_style('astraea-tokens', $base . 'astraea-tokens.css', [], '0.1.0');
        wp_enqueue_style('astraea-glass', $base . 'astraea-glass.css', ['astraea-tokens'], '0.1.0');
        wp_enqueue_style('astraea-login', $base . 'astraea-login.css', ['astraea-glass'], '0.1.0');
    }

    /**
     * Redirect login logo link to home URL.
     *
     * @param string $loginHeaderUrl
     * @return string
     */
    public static function filterHeaderUrl(string $loginHeaderUrl): string {
        return esc_url(home_url('/'));
    }

    /**
     * Replace login logo title/text.
     *
     * @param string $loginHeaderText
     * @return string
     */
    public static function filterHeaderText(string $loginHeaderText): string {
        return 'AstraeaOS WP — Core Authentication';
    }

    /**
     * Add dark glass body class.
     *
     * @param array<int, string> $classes
     * @return array<int, string>
     */
    public static function filterBodyClass(array $classes): array {
        $classes[] = 'astraea-login-portal';
        $classes[] = 'astraea-theme-dark';
        return $classes;
    }

    /**
     * Render bottom brand credits on login screen.
     */
    public static function renderLoginFooter(): void {
        ?>
        <div class="astraea-login-footer">
            <span>AstraeaOS &bull; Engineered by <a href="#" target="_blank" rel="noopener noreferrer">VisionGaiaTechnology</a></span>
        </div>
        <?php
    }
}
