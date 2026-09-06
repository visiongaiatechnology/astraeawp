<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\AdminUI;

use Astraea\AdminUI\Shell\ShellRenderer;
use Astraea\AdminUI\CommandPalette\CommandRegistry;
use Astraea\AdminUI\Dashboard\ControlCenter;
use Astraea\AdminUI\Notifications\NoticeCenter;
use Astraea\AdminUI\Login\LoginTheme;
use Astraea\AdminUI\Components\ComponentPreview;
use Astraea\Auth\SessionManager;

/**
 * AstraeaOS Admin UI Bootstrap & Engine Coordinator.
 *
 * Boots the Phase 2 Glass Admin Experience:
 * - Registers Shell Renderer, Command Palette, Control Center, Notice Center, Login Theme, Component Preview
 * - Enqueues local modular CSS & Vanilla ESNext JS
 * - Harmonizes Block Editor (Gutenberg) environment
 * - Zero external CDN dependencies
 *
 * @package Astraea\AdminUI
 */
final class AdminUIBootstrap {


    /**
     * Boot all Admin UI subsystems.
     */
    public static function boot(): void {
        // Always initialize Login Theme (runs on wp-login.php)
        LoginTheme::init();

        // Admin-only subsystems
        if (is_admin()) {
            ShellRenderer::init();
            CommandRegistry::init();
            ControlCenter::init();
            NoticeCenter::init();
            ComponentPreview::init();
            SessionManager::init();

            add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
            add_action('enqueue_block_editor_assets', [self::class, 'enqueueBlockEditorAssets']);
        }
    }

    /**
     * Enqueue Admin UI Styles and Vanilla JavaScript.
     *
     * @param string $hookSuffix
     */
    public static function enqueueAdminAssets(string $hookSuffix): void {
        $coreBase = function_exists('site_url')
            ? site_url('/astraea-core/AdminUI/assets/')
            : content_url('../astraea-core/AdminUI/assets/');
        $cssBase  = $coreBase . 'css/';
        $jsBase   = $coreBase . 'js/';

        // 1. Core Tokens & Glass System
        wp_enqueue_style('astraea-tokens', $cssBase . 'astraea-tokens.css', [], self::assetVersion('css/astraea-tokens.css'));
        wp_enqueue_style('astraea-glass', $cssBase . 'astraea-glass.css', ['astraea-tokens'], self::assetVersion('css/astraea-glass.css'));

        // 2. Shell Layout & Navigation
        wp_enqueue_style('astraea-shell', $cssBase . 'astraea-shell.css', ['astraea-glass'], self::assetVersion('css/astraea-shell.css'));

        // 3. Components, Forms, Modals, Badges
        wp_enqueue_style('astraea-components', $cssBase . 'astraea-components.css', ['astraea-shell'], self::assetVersion('css/astraea-components.css'));

        // 4. Modern Tables (wp-list-table)
        wp_enqueue_style('astraea-tables', $cssBase . 'astraea-tables.css', ['astraea-components'], self::assetVersion('css/astraea-tables.css'));

        // 5. Legacy WordPress Surface Adapter — unifies Core screens without DOM rewriting
        wp_enqueue_style('astraea-legacy-surfaces', $cssBase . 'astraea-legacy-surfaces.css', ['astraea-tables'], self::assetVersion('css/astraea-legacy-surfaces.css'));

        // 6. Dashboard / Control Center HUD
        wp_enqueue_style('astraea-dashboard', $cssBase . 'astraea-dashboard.css', ['astraea-legacy-surfaces'], self::assetVersion('css/astraea-dashboard.css'));

        // 7. Command Palette Overlay
        wp_enqueue_style('astraea-command-palette', $cssBase . 'astraea-command-palette.css', ['astraea-components'], self::assetVersion('css/astraea-command-palette.css'));

        // 8. Notification Center Drawer & Toasts
        wp_enqueue_style('astraea-notifications', $cssBase . 'astraea-notifications.css', ['astraea-components'], self::assetVersion('css/astraea-notifications.css'));
        wp_enqueue_style('astraea-workspace', $cssBase . 'astraea-workspace.css', ['astraea-dashboard', 'astraea-notifications'], self::assetVersion('css/astraea-workspace.css'));

        // 9. Admin Modular ESNext Engine
        wp_enqueue_script(
            'astraea-admin-js',
            $jsBase . 'astraea-admin.js',
            [],
            self::assetVersion('js/astraea-admin.js'),
            ['strategy' => 'defer', 'in_footer' => true]
        );

        // Localize config to JS
        wp_localize_script('astraea-admin-js', 'AstraeaConfig', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'cmdNonce'     => wp_create_nonce(CommandRegistry::NONCE_KEY),
            'sessionNonce' => wp_create_nonce(SessionManager::NONCE_ACTION),
            'siteUrl'      => site_url(),
            'adminUrl'     => admin_url(),
            'version'      => self::version(),
            'currentTheme' => 'dark',
        ]);
    }

    /**
     * Enqueue safe Gutenberg Block Editor harmonization style.
     */
    public static function enqueueBlockEditorAssets(): void {
        $cssBase = function_exists('site_url')
            ? site_url('/astraea-core/AdminUI/assets/css/')
            : content_url('../astraea-core/AdminUI/assets/css/');
        wp_enqueue_style('astraea-gutenberg', $cssBase . 'astraea-gutenberg.css', ['wp-edit-blocks'], self::assetVersion('css/astraea-gutenberg.css'));
    }

    /**
     * Generate a deterministic cache-busting version for a local UI asset.
     */
    public static function assetVersion(string $relativePath): string {
        if (
            $relativePath === ''
            || str_contains($relativePath, '..')
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._\/-]*\z/', $relativePath) !== 1
        ) {
            return self::version();
        }

        $assetPath = __DIR__ . '/assets/' . $relativePath;
        $modifiedAt = is_file($assetPath) ? filemtime($assetPath) : false;

        return $modifiedAt === false
            ? self::version()
            : self::version() . '.' . (string) $modifiedAt;
    }
    private static function version(): string {
        return defined('ASTRAEA_VERSION') ? (string)ASTRAEA_VERSION : 'unknown';
    }

}
