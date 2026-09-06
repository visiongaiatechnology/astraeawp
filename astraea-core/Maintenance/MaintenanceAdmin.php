<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Maintenance;

/**
 * Administration Screen for Astraea Maintenance Mode.
 *
 * @package Astraea\Maintenance
 */
final class MaintenanceAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_astraea_save_maintenance', [self::class, 'handleSave']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'options-general.php',
            'Astraea Maintenance Mode',
            'Maintenance Mode',
            'manage_options',
            'astraea-maintenance',
            [self::class, 'renderScreen']
        );
    }

    public static function handleSave(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'Maintenance', ['response' => 403]);
        }

        check_admin_referer('astraea_maintenance_save', '_astraea_nonce');

        $mode = sanitize_key($_POST['mode'] ?? 'disabled');
        $title = sanitize_text_field($_POST['title'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $ips = sanitize_textarea_field($_POST['whitelisted_ips'] ?? '');
        $token = sanitize_key($_POST['bypass_token'] ?? '');

        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        }

        MaintenanceController::updateConfig([
            'mode'            => $mode,
            'title'           => $title ?: 'Scheduled Maintenance',
            'message'         => $message ?: 'We are performing scheduled core maintenance to ensure optimal security and reliability. Please check back shortly.',
            'whitelisted_ips' => $ips,
            'bypass_token'    => $token,
        ]);

        wp_safe_redirect(admin_url('options-general.php?page=astraea-maintenance&status=saved'));
        exit;
    }

    public static function renderScreen(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', 'Maintenance', ['response' => 403]);
        }

        $config = MaintenanceController::getConfig();
        $mode = (string)($config['mode'] ?? 'disabled');
        $title = (string)($config['title'] ?? 'Scheduled Maintenance');
        $message = (string)($config['message'] ?? 'We are performing scheduled core maintenance to ensure optimal security and reliability. Please check back shortly.');
        $ips = (string)($config['whitelisted_ips'] ?? '');
        $token = (string)($config['bypass_token'] ?? bin2hex(random_bytes(16)));
        $nonce = wp_create_nonce('astraea_maintenance_save');
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 900px; margin: 24px auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea Maintenance & Coming Soon</h1>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">SEO-safe service interception (HTTP 503 + Retry-After) with administrative and secret token bypass.</p>
            </div>

            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 24px;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="astraea_save_maintenance">
                    <input type="hidden" name="_astraea_nonce" value="<?php echo esc_attr($nonce); ?>">

                    <!-- Mode Select -->
                    <div style="margin-bottom: 24px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #f1f5f9; margin-bottom: 8px;">Operating Mode</label>
                        <div style="display: flex; gap: 16px;">
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; color: #cbd5e1; font-size: 13px;">
                                <input type="radio" name="mode" value="disabled" <?php checked($mode, 'disabled'); ?>>
                                <span>Disabled (Normal Site Operation)</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; color: #cbd5e1; font-size: 13px;">
                                <input type="radio" name="mode" value="maintenance" <?php checked($mode, 'maintenance'); ?>>
                                <span style="color: #facc15; font-weight: 600;">Maintenance Mode (HTTP 503 + Retry-After)</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; color: #cbd5e1; font-size: 13px;">
                                <input type="radio" name="mode" value="coming_soon" <?php checked($mode, 'coming_soon'); ?>>
                                <span style="color: #38bdf8; font-weight: 600;">Coming Soon Mode (HTTP 200)</span>
                            </label>
                        </div>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #f1f5f9; margin-bottom: 6px;">Headline Title</label>
                        <input type="text" name="title" value="<?php echo esc_attr($title); ?>" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff; font-size: 13px;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #f1f5f9; margin-bottom: 6px;">Maintenance Message</label>
                        <textarea name="message" rows="4" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff; font-size: 13px;"><?php echo esc_textarea($message); ?></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #f1f5f9; margin-bottom: 6px;">IP Whitelist (One per line)</label>
                            <textarea name="whitelisted_ips" rows="3" placeholder="192.168.1.1&#10;203.0.113.50" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff; font-family: monospace; font-size: 12px;"><?php echo esc_textarea($ips); ?></textarea>
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #f1f5f9; margin-bottom: 6px;">Secret Bypass Token</label>
                            <input type="text" name="bypass_token" value="<?php echo esc_attr($token); ?>" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #38bdf8; font-family: monospace; font-size: 12px;">
                            <p style="font-size: 11px; color: #94a3b8; margin-top: 4px;">
                                Bypass URL: <code><?php echo esc_html(home_url('/?astraea_bypass=' . $token)); ?></code>
                            </p>
                        </div>
                    </div>

                    <button type="submit" class="button button-primary" style="background: #0284c7; border: 1px solid #38bdf8; padding: 8px 24px; border-radius: 6px; font-size: 13px; cursor: pointer;">
                        Save Maintenance Settings
                    </button>
                </form>
            </div>
        </div>
        <?php
    }
}
