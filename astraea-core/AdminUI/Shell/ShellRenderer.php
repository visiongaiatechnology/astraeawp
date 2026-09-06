<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\AdminUI\Shell;

use Astraea\AdminUI\Navigation\NavigationAdapter;

/**
 * ShellRenderer for AstraeaOS Glass Administration UI.
 *
 * Replaces legacy WordPress branding across the admin shell:
 * - Emits modern topbar with Astraea metallic cyan emblem
 * - Injects Command Palette trigger (Ctrl+K)
 * - Injects System Telemetry & GeDefense status indicators
 * - Provides theme mode switcher (Dark / Light / System)
 * - Injects breadcrumb hierarchy
 * - Modernizes user profile dropdown and quick actions
 * - Rewrites admin title and footer credits to AstraeaOS / VisionGaiaTechnology
 *
 * @package Astraea\AdminUI\Shell
 */
final class ShellRenderer {

    /**
     * URL path to official AstraeaOS logo.
     */
    public const LOGO_PATH = 'astraea-core/AdminUI/assets/img/astraea-brand-2026.png';

    /**
     * Register WordPress core hooks for admin shell transformation.
     */
    public static function init(): void {
        // Rewrite HTML title tag
        add_filter('admin_title', [self::class, 'filterAdminTitle'], 10, 2);

        // Replace footer branding & versioning
        add_filter('admin_footer_text', [self::class, 'filterAdminFooter'], 999);
        add_filter('update_footer', [self::class, 'filterUpdateFooter'], 999);

        // Customize admin bar nodes (priority 999 removes WP nodes and adds Astraea nodes)
        add_action('admin_bar_menu', [self::class, 'customizeAdminBar'], 999);

        // Inject modern topbar & breadcrumbs right after admin bar
        add_action('in_admin_header', [self::class, 'renderAstraeaHeader'], 1);

        // Add body classes for dark theme and glass shell
        add_filter('admin_body_class', [self::class, 'filterBodyClasses']);
    }

    /**
     * Rewrite admin title: "[Title] — AstraeaOS".
     *
     * @param string $adminTitle
     * @param string $title
     * @return string
     */
    public static function filterAdminTitle(string $adminTitle, string $title): string {
        return esc_html($title) . ' — AstraeaOS';
    }

    /**
     * Replace admin footer text.
     *
     * @param string $defaultText
     * @return string
     */
    public static function filterAdminFooter(string $defaultText): string {
        return sprintf(
            '<span class="astraea-footer-brand"><strong>AstraeaOS WP</strong> %s &bull; Powered by <span class="vgt-accent">VisionGaiaTechnology</span> &bull; Hardened Core Foundation</span>',
            esc_html(defined('ASTRAEA_VERSION') ? ASTRAEA_VERSION : \Astraea\Version::VERSION)
        );
    }

    /**
     * Replace update footer text (suppress legacy WordPress version notice).
     *
     * @param string $defaultText
     * @return string
     */
    public static function filterUpdateFooter(string $defaultText): string {
        return sprintf(
            '<span class="astraea-system-build">Build %s | PHP %s</span>',
            esc_html(php_uname('m')),
            esc_html(PHP_VERSION)
        );
    }

    /**
     * Add Astraea shell body classes.
     *
     * @param string $classes
     * @return string
     */
    public static function filterBodyClasses(string $classes): string {
        $extra = ' astraea-glass-shell astraea-theme-dark astraea-loaded';
        return trim($classes . $extra);
    }

    /**
     * Remove legacy WordPress nodes from admin bar and inject Astraea nodes.
     *
     * @param \WP_Admin_Bar $wpAdminBar
     */
    public static function customizeAdminBar(\WP_Admin_Bar $wpAdminBar): void {
        // Remove legacy WordPress branding nodes
        $wpAdminBar->remove_node('wp-logo');
        $wpAdminBar->remove_node('about');
        $wpAdminBar->remove_node('wporg');
        $wpAdminBar->remove_node('documentation');
        $wpAdminBar->remove_node('support-forums');
        $wpAdminBar->remove_node('feedback');

        // Remove WP 7.1 core command palette node to eliminate search redundancy
        $wpAdminBar->remove_node('command-palette');

        // Logo URL
        $logoUrl = function_exists('site_url')
            ? esc_url(site_url('/' . self::LOGO_PATH))
            : esc_url(content_url('../' . self::LOGO_PATH));

        // 1. Astraea Primary Brand Node
        $wpAdminBar->add_node([
            'id'    => 'astraea-brand',
            'title' => sprintf(
                '<span class="astraea-bar-brand"><img src="%s" alt="AstraeaOS" class="astraea-bar-logo" width="20" height="20" /><span class="astraea-bar-name">Astraea<strong>OS</strong></span><span class="astraea-bar-badge">%s</span></span>',
                $logoUrl,
                esc_html(defined('ASTRAEA_VERSION') ? ASTRAEA_VERSION : '0.1.0')
            ),
            'href'  => admin_url('index.php'),
            'meta'  => ['class' => 'astraea-brand-node'],
        ]);

        // Astraea Brand Submenu
        $wpAdminBar->add_node([
            'id'     => 'astraea-control-center',
            'parent' => 'astraea-brand',
            'title'  => 'Control Center',
            'href'   => admin_url('index.php'),
        ]);

        $wpAdminBar->add_node([
            'id'     => 'astraea-gedefense-status',
            'parent' => 'astraea-brand',
            'title'  => '<span class="astraea-status-pill secure">GeDefense: ACTIVE</span>',
            'href'   => admin_url('index.php?page=astraea-security'),
        ]);

        $wpAdminBar->add_node([
            'id'     => 'astraea-sysinfo',
            'parent' => 'astraea-brand',
            'title'  => 'System Telemetry',
            'href'   => admin_url('site-health.php'),
        ]);

        // 2. Command Palette Trigger in Admin Bar (Centered Pill)
        $wpAdminBar->add_node([
            'id'    => 'astraea-search-trigger',
            'title' => '<div class="astraea-cmd-trigger" id="astraea-cmd-trigger" role="button" tabindex="0" aria-label="Open Command Palette"><svg class="cmd-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg><span class="cmd-text">Search or run command...</span><span class="cmd-shortcut"><kbd>Ctrl</kbd><kbd>K</kbd></span></div>',
            'href'  => false,
            'meta'  => ['class' => 'astraea-search-node'],
        ]);

        // 3. GeDefense HUD Indicator (Top Secondary - Right Side)
        $wpAdminBar->add_node([
            'id'     => 'astraea-hud-badge',
            'parent' => 'top-secondary',
            'title'  => '<a href="' . esc_url(admin_url('index.php?page=astraea-security')) . '" class="astraea-hud-badge-link" title="GeDefense Multi-Layer Security Active"><span class="astraea-hud-badge"><span class="hud-dot"></span> GeDefense</span></a>',
            'href'   => false,
            'meta'   => ['class' => 'astraea-hud-node'],
        ]);

        // 4. Notification Center Trigger (Top Secondary - Right Side)
        $wpAdminBar->add_node([
            'id'     => 'astraea-notice-drawer-trigger',
            'parent' => 'top-secondary',
            'title'  => '<div class="astraea-bar-icon-btn astraea-drawer-toggle" id="astraea-drawer-toggle" role="button" tabindex="0" title="Notification Center" aria-label="Notifications"><svg class="drawer-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg><span class="astraea-notice-count-badge" id="astraea-notice-counter" style="display:none;">0</span></div>',
            'href'   => false,
            'meta'   => ['class' => 'astraea-drawer-node'],
        ]);

        // 5. Theme Switcher Node (Top Secondary - Right Side)
        $wpAdminBar->add_node([
            'id'     => 'astraea-theme-switcher',
            'parent' => 'top-secondary',
            'title'  => '<div class="astraea-bar-icon-btn astraea-theme-toggle" id="astraea-theme-toggle" role="button" tabindex="0" title="Toggle Theme (Dark / Light)" aria-label="Toggle Theme"><svg class="theme-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg></div>',
            'href'   => false,
            'meta'   => ['class' => 'astraea-theme-node'],
        ]);

        // 6. Astraea Internationalization (I18n) Language Switcher (Top Secondary - 9 Approved Languages)
        if (class_exists('\\Astraea\\I18n\\I18n')) {
            $currentLocale = \Astraea\I18n\I18n::getLocale();
            $languages     = \Astraea\I18n\I18n::APPROVED_LANGUAGES;
            $currentMeta   = $languages[$currentLocale] ?? $languages['de_DE'];
            $currentFlag   = $currentMeta['flag'] ?? '🌐';
            $currentCode   = strtoupper(explode('_', $currentLocale)[0]);

            $wpAdminBar->add_node([
                'id'     => 'astraea-language-switcher',
                'parent' => 'top-secondary',
                'title'  => sprintf(
                    '<span class="astraea-lang-trigger" title="%s"><span class="lang-flag">%s</span> <span class="lang-code">%s</span></span>',
                    esc_attr(($currentMeta['native'] ?? '') . ' (' . ($currentMeta['name'] ?? '') . ')'),
                    $currentFlag,
                    esc_html($currentCode)
                ),
                'href'   => '#',
                'meta'   => ['class' => 'astraea-lang-node'],
            ]);

            foreach ($languages as $code => $meta) {
                $isCurrent = ($code === $currentLocale);
                $switchUrl = function_exists('add_query_arg') ? add_query_arg('astraea_lang', $code) : '?astraea_lang=' . urlencode($code);

                $wpAdminBar->add_node([
                    'id'     => 'astraea-lang-opt-' . $code,
                    'parent' => 'astraea-language-switcher',
                    'title'  => sprintf(
                        '<span class="astraea-lang-item%s"><span class="lang-flag">%s</span> <span class="lang-name">%s</span>%s</span>',
                        $isCurrent ? ' is-active' : '',
                        $meta['flag'],
                        esc_html($meta['native'] ?? $meta['name']),
                        $isCurrent ? ' <span class="lang-check">✓</span>' : ''
                    ),
                    'href'   => esc_url($switchUrl),
                ]);
            }
        }
    }

    /**
     * Render the modern Astraea Topbar Sub-Header & Breadcrumbs.
     */
    public static function renderAstraeaHeader(): void {
        $breadcrumbs = NavigationAdapter::getBreadcrumbs();
        ?>
        <div id="astraea-topbar-extension" class="astraea-topbar-glass">
            <div class="astraea-breadcrumbs-container">
                <nav class="astraea-breadcrumbs" aria-label="Breadcrumbs">
                    <ol class="astraea-crumb-list">
                        <?php foreach ($breadcrumbs as $index => $crumb): ?>
                            <li class="astraea-crumb-item<?php echo ($index === count($breadcrumbs) - 1) ? ' is-current' : ''; ?>">
                                <?php if (!empty($crumb['url']) && $index < count($breadcrumbs) - 1): ?>
                                    <a href="<?php echo esc_url($crumb['url']); ?>" class="astraea-crumb-link">
                                        <?php echo esc_html($crumb['title']); ?>
                                    </a>
                                    <span class="astraea-crumb-separator" aria-hidden="true">/</span>
                                <?php else: ?>
                                    <span class="astraea-crumb-current" aria-current="page">
                                        <?php echo esc_html($crumb['title']); ?>
                                    </span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            </div>
            <div class="astraea-header-actions">
                <div class="astraea-quick-actions">
                    <?php if (current_user_can('edit_posts')): ?>
                        <a href="<?php echo esc_url(admin_url('post-new.php')); ?>" class="astraea-btn astraea-btn-sm astraea-btn-ghost" title="Create New Post">
                            <span class="btn-icon">+</span> Post
                        </a>
                    <?php endif; ?>
                    <?php if (current_user_can('edit_pages')): ?>
                        <a href="<?php echo esc_url(admin_url('post-new.php?post_type=page')); ?>" class="astraea-btn astraea-btn-sm astraea-btn-ghost" title="Create New Page">
                            <span class="btn-icon">+</span> Page
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Astraea Notification Drawer Container -->
        <aside id="astraea-notification-drawer" class="astraea-notification-drawer" aria-hidden="true" role="dialog" aria-label="System Notifications">
            <div class="drawer-header">
                <div class="drawer-title">
                    <span class="drawer-icon">&#x1F514;</span>
                    <h3>Notification Center</h3>
                </div>
                <button type="button" class="drawer-close" id="astraea-drawer-close" aria-label="Close Notifications">&times;</button>
            </div>
            <div class="drawer-body" id="astraea-drawer-notices">
                <div class="drawer-empty-state" id="astraea-empty-notices">
                    <div class="empty-icon">&#x2714;</div>
                    <p>All systems nominal. No pending alerts.</p>
                </div>
            </div>
        </aside>
        <div id="astraea-drawer-backdrop" class="astraea-drawer-backdrop"></div>
        <?php
    }
}
