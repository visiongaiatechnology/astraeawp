<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\SEO;

/**
 * Administration Screen for Astraea SEO Essentials.
 *
 * @package Astraea\SEO
 */
final class SeoAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_astraea_save_seo', [self::class, 'handleSave']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'options-general.php',
            'Astraea SEO Essentials',
            'SEO Essentials',
            'manage_options',
            'astraea-seo',
            [self::class, 'renderScreen']
        );
    }

    public static function handleSave(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'SEO', ['response' => 403]);
        }

        check_admin_referer('astraea_seo_save', '_astraea_nonce');

        $desc = sanitize_text_field($_POST['default_description'] ?? '');
        $noindexArchives = !empty($_POST['noindex_archives']);
        $img = esc_url_raw($_POST['default_image'] ?? '');

        update_option('astraea_seo_default_description', $desc);
        update_option('astraea_seo_noindex_archives', $noindexArchives);
        update_option('astraea_seo_default_image', $img);

        wp_safe_redirect(admin_url('options-general.php?page=astraea-seo&status=saved'));
        exit;
    }

    public static function renderScreen(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', 'SEO', ['response' => 403]);
        }

        $conflict = ConflictDetector::check();
        $defaultDesc = (string)get_option('astraea_seo_default_description', '');
        $noindexArchives = (bool)get_option('astraea_seo_noindex_archives', true);
        $defaultImg = (string)get_option('astraea_seo_default_image', '');
        $nonce = wp_create_nonce('astraea_seo_save');
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 900px; margin: 24px auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea SEO Essentials</h1>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">Clean technical SEO foundation, OpenGraph cards, and Schema.org structured data.</p>
            </div>

            <?php if ($conflict['has_conflict']): ?>
                <div style="background: rgba(234, 179, 8, 0.15); border: 1px solid rgba(250, 204, 21, 0.3); border-radius: 12px; padding: 18px; margin-bottom: 24px;">
                    <h4 style="color: #facc15; margin: 0 0 6px;">External SEO Engine Active: <?php echo esc_html((string)$conflict['plugin_name']); ?></h4>
                    <p style="color: #cbd5e1; font-size: 13px; margin: 0;">
                        Astraea has automatically suspended duplicate meta tag output to protect search engine rankings and prevent conflicting directives.
                    </p>
                </div>
            <?php endif; ?>

            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 24px;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="astraea_save_seo">
                    <input type="hidden" name="_astraea_nonce" value="<?php echo esc_attr($nonce); ?>">

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #f1f5f9; margin-bottom: 6px;">Default Meta Description</label>
                        <textarea name="default_description" rows="3" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff; font-size: 13px;"><?php echo esc_textarea($defaultDesc); ?></textarea>
                        <p style="font-size: 11px; color: #94a3b8; margin-top: 4px;">Fallback description used on the homepage and archives when no specific excerpt exists.</p>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #f1f5f9; margin-bottom: 6px;">Social Sharing Image (OpenGraph / Twitter)</label>
                        <input type="url" name="default_image" value="<?php echo esc_attr($defaultImg); ?>" placeholder="https://example.com/social-preview.jpg" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff; font-size: 13px;">
                    </div>

                    <div style="margin-bottom: 24px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #cbd5e1; font-size: 13px;">
                            <input type="checkbox" name="noindex_archives" value="1" <?php checked($noindexArchives); ?>>
                            <span>Apply <code style="color: #38bdf8;">noindex</code> to Author and Date Archives (Prevents duplicate content)</span>
                        </label>
                    </div>

                    <button type="submit" class="button button-primary" style="background: #0284c7; border: 1px solid #38bdf8; padding: 8px 24px; border-radius: 6px; font-size: 13px; cursor: pointer;">
                        Save SEO Settings
                    </button>
                </form>
            </div>
        </div>
        <?php
    }
}
