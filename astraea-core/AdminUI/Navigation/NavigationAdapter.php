<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\AdminUI\Navigation;

/**
 * AstraeaOS Navigation Adapter & Categorizer.
 *
 * Intercepts WordPress core and plugin registered admin menus ($menu, $submenu),
 * preserving 100% ecosystem compatibility (add_menu_page, add_submenu_page)
 * while classifying items into the structured Astraea Navigation Model:
 * - CONTENT (Dashboard, Posts, Media, Pages, Comments)
 * - DESIGN (Appearance, Themes, Site Editor)
 * - SYSTEM (Plugins, Users, Tools, Settings)
 * - ASTRAEA (Security HUD, System Health, Diagnostics)
 * - EXTENSIONS (Dynamically discovered third-party plugin items)
 *
 * @package Astraea\AdminUI\Navigation
 */
final class NavigationAdapter {

    public const CAT_CONTENT    = 'CONTENT';
    public const CAT_DESIGN     = 'DESIGN';
    public const CAT_SYSTEM     = 'SYSTEM';
    public const CAT_ASTRAEA    = 'ASTRAEA';
    public const CAT_EXTENSIONS = 'EXTENSIONS';

    /**
     * Map known core menu slugs to their categorical domain.
     */
    private const CORE_SLUG_MAP = [
        // Content
        'index.php'                 => self::CAT_CONTENT,
        'edit.php'                  => self::CAT_CONTENT,
        'upload.php'                => self::CAT_CONTENT,
        'edit.php?post_type=page'   => self::CAT_CONTENT,
        'edit-comments.php'         => self::CAT_CONTENT,

        // Design
        'themes.php'                => self::CAT_DESIGN,
        'site-editor.php'           => self::CAT_DESIGN,
        'customize.php'             => self::CAT_DESIGN,

        // System
        'plugins.php'               => self::CAT_SYSTEM,
        'users.php'                 => self::CAT_SYSTEM,
        'profile.php'               => self::CAT_SYSTEM,
        'tools.php'                 => self::CAT_SYSTEM,
        'options-general.php'       => self::CAT_SYSTEM,

        // Astraea Core
        'astraea-security'          => self::CAT_ASTRAEA,
        'astraea-health'            => self::CAT_ASTRAEA,
        'astraea-performance'       => self::CAT_ASTRAEA,
    ];

    /**
     * Parse active WordPress menus into structured categories.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function getCategorizedMenu(): array {
        global $menu, $submenu;

        $categories = [
            self::CAT_CONTENT    => [],
            self::CAT_DESIGN     => [],
            self::CAT_SYSTEM     => [],
            self::CAT_ASTRAEA    => [],
            self::CAT_EXTENSIONS => [],
        ];

        if (!isset($menu) || !is_array($menu)) {
            return $categories;
        }

        foreach ($menu as $item) {
            // Ignore separators
            if (empty($item[2]) || str_starts_with((string) $item[2], 'separator')) {
                continue;
            }

            $title       = (string) ($item[0] ?? '');
            $capability  = (string) ($item[1] ?? 'read');
            $slug        = (string) ($item[2] ?? '');
            $icon        = (string) ($item[6] ?? 'dashicons-admin-generic');
            $classes     = (string) ($item[4] ?? '');

            // Capability check
            if (function_exists('current_user_can') && !current_user_can($capability)) {
                continue;
            }

            // Strip notification counters or tags from title
            $cleanTitle = wp_strip_all_tags($title);
            if (str_contains($title, '<span')) {
                // Extract badge count if present
                preg_match('/<span class=[\'"][^\'"]*count[^\'"]*[\'"]>([^<]+)<\/span>/i', $title, $m);
                $badge = $m[1] ?? '';
            } else {
                $badge = '';
            }

            $category = self::determineCategory($slug);

            // Submenu items
            $subItems = [];
            if (isset($submenu[$slug]) && is_array($submenu[$slug])) {
                foreach ($submenu[$slug] as $sub) {
                    $subCap = (string) ($sub[1] ?? 'read');
                    if (function_exists('current_user_can') && !current_user_can($subCap)) {
                        continue;
                    }
                    $subItems[] = [
                        'title' => wp_strip_all_tags((string) $sub[0]),
                        'slug'  => (string) $sub[2],
                        'url'   => self::buildMenuUrl((string) $sub[2], $slug),
                    ];
                }
            }

            $categories[$category][] = [
                'title'    => $cleanTitle,
                'slug'     => $slug,
                'url'      => self::buildMenuUrl($slug),
                'icon'     => $icon,
                'badge'    => $badge,
                'classes'  => $classes,
                'children' => $subItems,
            ];
        }

        return $categories;
    }

    /**
     * Determine category domain for a given slug.
     */
    public static function determineCategory(string $slug): string {
        if (isset(self::CORE_SLUG_MAP[$slug])) {
            return self::CORE_SLUG_MAP[$slug];
        }

        // Custom Post Types typically start with edit.php?post_type=
        if (str_starts_with($slug, 'edit.php')) {
            return self::CAT_CONTENT;
        }

        // Astraea prefixes
        if (str_starts_with($slug, 'astraea-')) {
            return self::CAT_ASTRAEA;
        }

        // Default all other third-party plugin pages to EXTENSIONS
        return self::CAT_EXTENSIONS;
    }

    /**
     * Build appropriate admin URL for a menu slug.
     */
    public static function buildMenuUrl(string $slug, string $parentSlug = ''): string {
        if (str_contains($slug, '.php')) {
            return admin_url($slug);
        }

        // Plugin sub-page
        if ($parentSlug !== '' && str_contains($parentSlug, '.php')) {
            return admin_url($parentSlug . '?page=' . $slug);
        }

        return admin_url('admin.php?page=' . $slug);
    }

    /**
     * Generate structured breadcrumb array for current admin view.
     *
     * @return array<int, array{title: string, url: string|null}>
     */
    public static function getBreadcrumbs(): array {
        global $title, $pagenow, $typenow;

        $crumbs = [
            ['title' => 'AstraeaOS', 'url' => admin_url('index.php')],
        ];

        $pageNowStr = (string) ($pagenow ?? 'index.php');

        if ($pageNowStr === 'index.php') {
            $crumbs[] = ['title' => 'Control Center', 'url' => null];
            return $crumbs;
        }

        // Detect category
        $category = self::determineCategory($pageNowStr);
        $crumbs[] = ['title' => ucfirst(strtolower($category)), 'url' => null];

        // Specific screen name
        $pageTitle = !empty($title) ? wp_strip_all_tags($title) : 'Overview';
        if ($typenow !== null && $typenow !== '') {
            $postTypeObj = get_post_type_object($typenow);
            if ($postTypeObj) {
                $crumbs[] = ['title' => (string) $postTypeObj->labels->name, 'url' => admin_url('edit.php?post_type=' . $typenow)];
            }
        }

        $crumbs[] = ['title' => $pageTitle, 'url' => null];

        return $crumbs;
    }
}
