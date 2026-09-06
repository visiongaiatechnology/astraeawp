<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Database;

use Astraea\Auth\StepUpAuthService;

/**
 * Administration Screen for Astraea Database Maintenance.
 *
 * @package Astraea\Database
 */
final class DatabaseAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_astraea_run_db_cleanup', [self::class, 'handleCleanup']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'tools.php',
            'Astraea Database Maintenance',
            'DB Maintenance',
            'manage_options',
            'astraea-database',
            [self::class, 'renderScreen']
        );
    }

    public static function handleCleanup(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'Database', ['response' => 403]);
        }

        check_admin_referer('astraea_db_cleanup', '_astraea_nonce');

        // Step-Up authentication required before destructive cleanup
        if (class_exists(StepUpAuthService::class) && !StepUpAuthService::isCurrentSessionVerified()) {
            wp_safe_redirect(StepUpAuthService::getVerificationUrl(admin_url('tools.php?page=astraea-database')));
            exit;
        }

        $categories = isset($_POST['categories']) && is_array($_POST['categories']) ? array_map('sanitize_key', $_POST['categories']) : [];

        if (empty($categories)) {
            wp_safe_redirect(admin_url('tools.php?page=astraea-database&status=none_selected'));
            exit;
        }

        try {
            $result = MaintenanceExecutor::execute($categories);
            wp_safe_redirect(admin_url('tools.php?page=astraea-database&status=cleaned&deleted=' . (int)$result['deleted_rows']));
        } catch (\Throwable $e) {
            wp_safe_redirect(admin_url('tools.php?page=astraea-database&error=' . urlencode($e->getMessage())));
        }
        exit;
    }

    public static function renderScreen(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', 'Database', ['response' => 403]);
        }

        $audit = MaintenanceAnalyzer::analyze();
        $nonce = wp_create_nonce('astraea_db_cleanup');
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 900px; margin: 24px auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea Database Maintenance</h1>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">Dry-run record auditing with mandatory pre-cleanup Vault snapshots.</p>
            </div>

            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 24px;">
                <h3 style="color: #f8fafc; margin: 0 0 16px; font-size: 16px;">Dry-Run Storage Analysis</h3>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="astraea_run_db_cleanup">
                    <input type="hidden" name="_astraea_nonce" value="<?php echo esc_attr($nonce); ?>">

                    <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px;">
                        <?php foreach ($audit as $key => $data): ?>
                            <label style="display: flex; justify-content: space-between; align-items: center; background: rgba(30, 41, 59, 0.4); border: 1px solid rgba(255, 255, 255, 0.06); padding: 14px 18px; border-radius: 8px; cursor: pointer;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <input type="checkbox" name="categories[]" value="<?php echo esc_attr($key); ?>" <?php if ($data['count'] > 0) echo 'checked'; ?>>
                                    <div>
                                        <div style="font-weight: 600; color: #f1f5f9; font-size: 13px;"><?php echo esc_html($data['label']); ?></div>
                                        <div style="font-size: 11px; color: #94a3b8;">Reclaimable: ~<?php echo number_format((int)$data['estimated_bytes'] / 1024, 1); ?> KB</div>
                                    </div>
                                </div>
                                <span style="font-family: monospace; font-size: 13px; font-weight: 700; color: <?php echo $data['count'] > 0 ? '#38bdf8' : '#64748b'; ?>;">
                                    <?php echo (int)$data['count']; ?> items
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="font-size: 12px; color: #94a3b8;">
                            🛡️ An automatic <strong>Astraea Vault snapshot</strong> is created before records are purged.
                        </div>
                        <button type="submit" class="button button-primary" style="background: #0284c7; border: 1px solid #38bdf8; padding: 8px 24px; border-radius: 6px; font-size: 13px; cursor: pointer;" onclick="return confirm('Execute cleanup for selected items? An automatic Vault snapshot will be created.');">
                            Run Selected Cleanup
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}
