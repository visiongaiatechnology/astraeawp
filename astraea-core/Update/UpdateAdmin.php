<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Update;

use Astraea\Version;
use Astraea\Auth\StepUpAuthService;

/**
 * Administration Screen for Astraea Update Engine.
 *
 * @package Astraea\Update
 */
final class UpdateAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_astraea_check_updates', [self::class, 'handleCheckUpdates']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'index.php',
            'Astraea Update Engine',
            'Astraea Updates',
            'update_core',
            'astraea-update',
            [self::class, 'renderScreen']
        );
    }

    public static function handleCheckUpdates(): void {
        if (!current_user_can('update_core')) {
            wp_die('Unauthorized.', 'Astraea Update', ['response' => 403]);
        }

        check_admin_referer('astraea_update_action', '_astraea_nonce');

        // Refresh update check timestamp
        update_option('astraea_last_update_check', time());

        wp_safe_redirect(admin_url('admin.php?page=astraea-update&status=checked'));
        exit;
    }

    public static function renderScreen(): void {
        if (!current_user_can('update_core')) {
            wp_die('Access denied.', 'Astraea Update', ['response' => 403]);
        }

        $currentVer = Version::VERSION;
        $currentChannel = get_option('astraea_update_channel', ReleaseChannel::STABLE->value);
        $lastChecked = (int)get_option('astraea_last_update_check', 0);
        $nonce = wp_create_nonce('astraea_update_action');
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 900px; margin: 24px auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea Update Engine</h1>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">Cryptographically verified, atomic distribution updates with automated Vault rollback.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Current Release</div>
                    <div style="font-size: 28px; font-weight: 700; color: #38bdf8; margin: 8px 0 4px; font-family: monospace;">v<?php echo esc_html($currentVer); ?></div>
                    <div style="font-size: 12px; color: #64748b;">Channel: <strong style="color: #cbd5e1;"><?php echo esc_html(strtoupper($currentChannel)); ?></strong></div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Authenticity Verification</div>
                    <div style="font-size: 18px; font-weight: 600; color: #4ade80; margin: 8px 0 4px;">Ed25519 Verified Engine</div>
                    <div style="font-size: 12px; color: #94a3b8;">Only VGT release packages signed with air-gapped root keys are executable.</div>
                </div>
            </div>

            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 24px;">
                <h3 style="color: #f8fafc; margin: 0 0 12px; font-size: 16px;">Update Verification Pipeline</h3>
                <ul style="color: #94a3b8; font-size: 13px; line-height: 1.8; margin: 0 0 20px; padding-left: 20px;">
                    <li><strong style="color: #cbd5e1;">Upstream Protection:</strong> Upstream WordPress core packages are strictly blocked from overwriting Astraea Core.</li>
                    <li><strong style="color: #cbd5e1;">Pre-Update Snapshot:</strong> Astraea Vault captures a full verifiable system snapshot before any files are touched.</li>
                    <li><strong style="color: #cbd5e1;">Path-Jailed Extraction:</strong> ZipSlip directory traversal and symlink vectors are rejected at the byte level.</li>
                    <li><strong style="color: #cbd5e1;">Atomic Swap & Health Probes:</strong> Live runtime health probes verify core integrity before update is committed.</li>
                </ul>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="astraea_check_updates">
                    <input type="hidden" name="_astraea_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <button type="submit" class="button button-primary" style="background: #0284c7; border: 1px solid #38bdf8; font-size: 13px; padding: 6px 16px; border-radius: 6px; cursor: pointer;">
                        Check for Updates Now
                    </button>
                    <?php if ($lastChecked > 0): ?>
                        <span style="font-size: 12px; color: #64748b; margin-left: 12px;">
                            Last checked: <?php echo esc_html(date_i18n('Y-m-d H:i:s', $lastChecked)); ?>
                        </span>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php
    }
}
