<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\AdminUI\CommandPalette;

use Astraea\AdminUI\Navigation\NavigationAdapter;

/**
 * CommandPalette Registry & Live Search Handler.
 *
 * Provides lightning-fast keyboard-first navigation via Ctrl+K:
 * - Admin screens, menus, and submenus
 * - System actions (Flush cache, Switch theme, Toggle HUD)
 * - Quick creation (New Post, New Page, Add User)
 * - Search posts, pages, and media (with cap checks and nonces)
 *
 * @package Astraea\AdminUI\CommandPalette
 */
final class CommandRegistry {

    public const AJAX_ACTION = 'astraea_command_search';
    public const NONCE_KEY   = 'astraea_cmd_palette_nonce';

    /**
     * Initialize Command Palette hooks.
     */
    public static function init(): void {
        add_action('admin_footer', [self::class, 'renderModalMarkup']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'handleSearchAjax']);
    }

    /**
     * Render the Command Palette Modal in admin footer.
     */
    public static function renderModalMarkup(): void {
        ?>
        <div id="astraea-command-modal" class="astraea-modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cmd-palette-label">
            <div class="astraea-cmd-box astraea-glass-surface-l3">
                <div class="astraea-cmd-searchbar">
                    <svg class="astraea-cmd-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text"
                           id="astraea-cmd-input"
                           class="astraea-cmd-input"
                           placeholder="Type a command or search (e.g. 'Posts', 'Cache', 'Security')..."
                           autocomplete="off"
                           spellcheck="false"
                           aria-label="Search or command query" />
                    <span class="astraea-cmd-esc-hint"><kbd>ESC</kbd> to close</span>
                </div>
                <div class="astraea-cmd-results" id="astraea-cmd-results" role="listbox">
                    <!-- Dynamic results injected by astraea-admin.js -->
                </div>
                <div class="astraea-cmd-footer">
                    <div class="astraea-cmd-shortcuts">
                        <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> Navigate</span>
                        <span><kbd>&crarr;</kbd> Select</span>
                        <span><kbd>ESC</kbd> Close</span>
                    </div>
                    <div class="astraea-cmd-branding">
                        <span>AstraeaOS Command Kernel</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle AJAX search requests from Command Palette.
     */
    public static function handleSearchAjax(): void {
        // Nonce check (Header X-WP-Nonce preferred or POST body fallback; GET token rejected to prevent URL leakage)
        $nonce = '';
        if (isset($_SERVER['HTTP_X_WP_NONCE'])) {
            $nonce = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE']));
        } elseif (isset($_POST['nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
        }

        if (!wp_verify_nonce($nonce, self::NONCE_KEY)) {
            wp_send_json_error(['message' => 'Security token invalid.'], 403);
            return;
        }

        // Capability check
        if (!current_user_can('read')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
            return;
        }

        $query = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : (isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '');
        $results = self::searchCommands($query);

        wp_send_json_success($results);
    }

    /**
     * Search commands, navigation menus, and content.
     *
     * @param string $query
     * @return array<int, array{id: string, title: string, category: string, icon: string, url: string, shortcut: string}>
     */
    public static function searchCommands(string $query): array {
        $q = strtolower(trim($query));
        $matches = [];

        // 1. Static System Actions
        $systemActions = self::getStaticActions();
        foreach ($systemActions as $action) {
            if ($q === '' || str_contains(strtolower($action['title']), $q) || str_contains(strtolower($action['category']), $q)) {
                $matches[] = $action;
            }
        }

        // 2. Admin Menus & Submenus
        $categorized = NavigationAdapter::getCategorizedMenu();
        foreach ($categorized as $catName => $items) {
            foreach ($items as $item) {
                if ($q === '' || str_contains(strtolower($item['title']), $q)) {
                    $matches[] = [
                        'id'       => 'nav_' . sanitize_title($item['slug']),
                        'title'    => $item['title'],
                        'category' => 'Navigation (' . $catName . ')',
                        'icon'     => '▸',
                        'url'      => $item['url'],
                        'shortcut' => '',
                    ];
                }

                if (!empty($item['children'])) {
                    foreach ($item['children'] as $sub) {
                        if ($q === '' || str_contains(strtolower($sub['title']), $q)) {
                            $matches[] = [
                                'id'       => 'nav_' . sanitize_title($sub['slug']),
                                'title'    => $item['title'] . ' › ' . $sub['title'],
                                'category' => 'Navigation (' . $catName . ')',
                                'icon'     => '▸',
                                'url'      => $sub['url'],
                                'shortcut' => '',
                            ];
                        }
                    }
                }
            }
        }

        // 3. If query is non-empty, search recent Posts and Pages with strict capability checks
        if ($q !== '' && current_user_can('edit_posts')) {
            $allowedStatuses = ['publish'];
            if (current_user_can('edit_posts')) {
                $allowedStatuses[] = 'draft';
            }
            if (current_user_can('read_private_posts') || current_user_can('read_private_pages')) {
                $allowedStatuses[] = 'private';
            }

            $posts = get_posts([
                's'              => $q,
                'posts_per_page' => 5,
                'post_status'    => $allowedStatuses,
                'post_type'      => ['post', 'page'],
            ]);

            foreach ($posts as $post) {
                // Defense-in-depth: skip private posts if user cannot read private content of this type
                if ($post->post_status === 'private') {
                    $requiredCap = ($post->post_type === 'page') ? 'read_private_pages' : 'read_private_posts';
                    if (!current_user_can($requiredCap) && !current_user_can('edit_post', $post->ID)) {
                        continue;
                    }
                }

                $matches[] = [
                    'id'       => 'post_' . $post->ID,
                    'title'    => get_the_title($post) . ' (' . ucfirst($post->post_status) . ')',
                    'category' => ucfirst($post->post_type),
                    'icon'     => '📄',
                    'url'      => get_edit_post_link($post->ID, 'raw') ?? '',
                    'shortcut' => '',
                ];
            }
        }

        return array_slice($matches, 0, 20);
    }

    /**
     * Get system built-in actions.
     *
     * @return array<int, array{id: string, title: string, category: string, icon: string, url: string, shortcut: string}>
     */
    public static function getStaticActions(): array {
        $actions = [];

        $actions[] = [
            'id'       => 'act_dashboard',
            'title'    => 'Go to Control Center Dashboard',
            'category' => 'System',
            'icon'     => '⌂',
            'url'      => admin_url('index.php'),
            'shortcut' => 'G + D',
        ];

        if (current_user_can('edit_posts')) {
            $actions[] = [
                'id'       => 'act_new_post',
                'title'    => 'Create New Post',
                'category' => 'Content',
                'icon'     => '+',
                'url'      => admin_url('post-new.php'),
                'shortcut' => 'C + P',
            ];
        }

        if (current_user_can('edit_pages')) {
            $actions[] = [
                'id'       => 'act_new_page',
                'title'    => 'Create New Page',
                'category' => 'Content',
                'icon'     => '+',
                'url'      => admin_url('post-new.php?post_type=page'),
                'shortcut' => '',
            ];
        }

        if (current_user_can('upload_files')) {
            $actions[] = [
                'id'       => 'act_upload_media',
                'title'    => 'Upload Media File',
                'category' => 'Media',
                'icon'     => '📁',
                'url'      => admin_url('media-new.php'),
                'shortcut' => '',
            ];
        }

        if (current_user_can('manage_options')) {
            $actions[] = [
                'id'       => 'act_site_health',
                'title'    => 'System Health & Telemetry',
                'category' => 'Diagnostics',
                'icon'     => '♥',
                'url'      => admin_url('site-health.php'),
                'shortcut' => '',
            ];

            $actions[] = [
                'id'       => 'act_gedefense',
                'title'    => 'GeDefense Security HUD',
                'category' => 'Security',
                'icon'     => '🛡',
                'url'      => admin_url('index.php?page=astraea-security'),
                'shortcut' => '',
            ];
        }

        $actions[] = [
            'id'       => 'act_view_site',
            'title'    => 'Open Public Front-End',
            'category' => 'Site',
            'icon'     => '↗',
            'url'      => home_url('/'),
            'shortcut' => '',
        ];

        return $actions;
    }
}
