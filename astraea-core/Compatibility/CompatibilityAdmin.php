<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Compatibility;

use Astraea\Auth\StepUpAuthService;

/**
 * Administration Screen for Astraea Compatibility Center.
 *
 * @package Astraea\Compatibility
 */
final class CompatibilityAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_astraea_save_compatibility', [self::class, 'handleSave']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'tools.php',
            'Astraea Compatibility Center',
            'Compatibility',
            'manage_options',
            'astraea-compatibility',
            [self::class, 'renderScreen']
        );
    }

    public static function handleSave(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'Compatibility', ['response' => 403]);
        }

        check_admin_referer('astraea_compat_save', '_astraea_nonce');

        // Step-Up authentication required before altering security compatibility boundaries
        if (class_exists(StepUpAuthService::class) && !StepUpAuthService::isCurrentSessionVerified()) {
            wp_safe_redirect(StepUpAuthService::getVerificationUrl(admin_url('tools.php?page=astraea-compatibility')));
            exit;
        }

        $submitted = isset($_POST['flags']) && is_array($_POST['flags']) ? $_POST['flags'] : [];
        $flags = [];

        foreach (CompatibilityFlag::cases() as $case) {
            $flags[$case->value] = !empty($submitted[$case->value]);
        }

        CompatibilityManager::setFlags($flags);
        wp_safe_redirect(admin_url('tools.php?page=astraea-compatibility&status=saved'));
        exit;
    }

    public static function renderScreen(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', 'Compatibility', ['response' => 403]);
        }

        $activeFlags = CompatibilityManager::getFlags();
        $nonce = wp_create_nonce('astraea_compat_save');
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 900px; margin: 24px auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea Compatibility Center</h1>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">Granular interoperability exceptions for legacy plugins without disabling global security baselines.</p>
            </div>

            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 24px;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="astraea_save_compatibility">
                    <input type="hidden" name="_astraea_nonce" value="<?php echo esc_attr($nonce); ?>">

                    <div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 24px;">
                        <?php foreach (CompatibilityFlag::cases() as $flag):
                            $isEnabled = !empty($activeFlags[$flag->value]);
                            $riskColor = match ($flag->risk()) {
                                'CRITICAL' => 'background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.3);',
                                'HIGH'     => 'background: rgba(234, 179, 8, 0.15); color: #facc15; border: 1px solid rgba(250, 204, 21, 0.3);',
                                default    => 'background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(96, 165, 250, 0.3);',
                            };
                        ?>
                            <div style="background: rgba(30, 41, 59, 0.4); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 8px; padding: 18px;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                    <div>
                                        <div style="font-weight: 600; color: #f1f5f9; font-size: 14px;"><?php echo esc_html($flag->label()); ?></div>
                                        <div style="font-size: 11px; font-family: monospace; color: #64748b; margin-top: 2px;"><code><?php echo esc_html($flag->value); ?></code></div>
                                    </div>
                                    <span style="font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 4px; <?php echo $riskColor; ?>">
                                        <?php echo esc_html($flag->risk()); ?> RISK
                                    </span>
                                </div>
                                <p style="font-size: 12px; color: #94a3b8; line-height: 1.5; margin: 0 0 12px;">
                                    <?php echo esc_html($flag->defaultReason()); ?>
                                </p>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #cbd5e1; font-size: 12px; font-weight: 600;">
                                    <input type="checkbox" name="flags[<?php echo esc_attr($flag->value); ?>]" value="1" <?php checked($isEnabled); ?>>
                                    <span>Enable exception for this feature</span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 12px; color: #94a3b8;">🔐 Changes are logged to the Security Event Fabric.</span>
                        <button type="submit" class="button button-primary" style="background: #0284c7; border: 1px solid #38bdf8; padding: 8px 24px; border-radius: 6px; font-size: 13px; cursor: pointer;">
                            Save Compatibility Exceptions
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}
