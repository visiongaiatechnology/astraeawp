<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Migration;

use Astraea\Auth\StepUpAuthService;

/**
 * Migration Wizard Admin Interface for AstraeaOS WP.
 *
 * @package Astraea\Migration
 */
final class MigrationAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_astraea_execute_migration', [self::class, 'handleExecuteMigration']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'tools.php',
            'WordPress → Astraea Migration Wizard',
            'Astraea Migration',
            'manage_options',
            'astraea-migration',
            [self::class, 'renderScreen']
        );
    }

    public static function handleExecuteMigration(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'Migration', ['response' => 403]);
        }

        check_admin_referer('astraea_migration_action', '_astraea_nonce');

        // Step-Up authentication required before migration
        if (class_exists(StepUpAuthService::class) && !StepUpAuthService::isCurrentSessionVerified()) {
            wp_safe_redirect(StepUpAuthService::getVerificationUrl(admin_url('tools.php?page=astraea-migration')));
            exit;
        }

        try {
            $receipt = MigrationWizard::executeMigration();
            wp_safe_redirect(admin_url('tools.php?page=astraea-migration&status=success'));
        } catch (\Throwable $e) {
            error_log('[SEC] Migration error: ' . $e->getMessage());
            wp_safe_redirect(admin_url('tools.php?page=astraea-migration&error=' . urlencode($e->getMessage())));
        }
        exit;
    }

    public static function renderScreen(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', 'Migration', ['response' => 403]);
        }

        $env = EnvironmentScanner::scan();
        $analysis = PluginReplacementAnalyzer::analyze((array)$env['active_plugins']);
        $receipt = get_option(MigrationWizard::OPTION_MIGRATION_RECEIPT, null);
        $nonce = wp_create_nonce('astraea_migration_action');
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 1000px; margin: 24px auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">WordPress → Astraea Migration Wizard</h1>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">Transition to Astraea First-Party Kernel with zero plugin lock-in and automated Vault backup.</p>
            </div>

            <?php if ($receipt !== null): ?>
                <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(74, 222, 128, 0.3); border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: #4ade80; font-size: 18px;">✔</span>
                        <h3 style="color: #4ade80; margin: 0; font-size: 16px;">Migration Successfully Executed</h3>
                    </div>
                    <p style="color: #cbd5e1; font-size: 13px; margin: 8px 0 0;">
                        This system is actively running on the Astraea First-Party Kernel. Snapshot ID: <code><?php echo esc_html((string)$receipt['snapshot_id']); ?></code>.
                    </p>
                </div>
            <?php endif; ?>

            <!-- Plugin Reduction Ratio Summary -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px;">
                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Active Plugins (Before)</div>
                    <div style="font-size: 28px; font-weight: 700; color: #f8fafc; margin: 8px 0 4px;"><?php echo (int)$analysis['total_before']; ?></div>
                    <div style="font-size: 12px; color: #64748b;">Current plugin stack</div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Potentially Replaced</div>
                    <div style="font-size: 28px; font-weight: 700; color: #38bdf8; margin: 8px 0 4px;"><?php echo (int)$analysis['replaceable_count']; ?></div>
                    <div style="font-size: 12px; color: #64748b;">Supported by native Astraea systems</div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Projected Plugins (After)</div>
                    <div style="font-size: 28px; font-weight: 700; color: #4ade80; margin: 8px 0 4px;"><?php echo (int)$analysis['total_after']; ?></div>
                    <div style="font-size: 12px; color: #64748b;">Specialized business applications only</div>
                </div>
            </div>

            <!-- Replacement Inventory Table -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 24px; margin-bottom: 24px;">
                <h3 style="color: #f8fafc; margin: 0 0 16px; font-size: 16px;">Feature Replacement Recommendations</h3>
                <p style="color: #94a3b8; font-size: 13px; margin-bottom: 20px;">
                    Third-party plugins are <strong>never uninstalled automatically</strong>. Astraea activates integrated first-party replacements and allows you to test before deactivating existing plugins.
                </p>

                <?php if (empty($analysis['replaceable'])): ?>
                    <p style="color: #64748b; font-size: 13px;">No common third-party infrastructure plugins detected.</p>
                <?php else: ?>
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; color: #cbd5e1;">
                        <thead>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); color: #94a3b8; font-size: 11px; text-transform: uppercase;">
                                <th style="padding: 10px 12px;">Plugin</th>
                                <th style="padding: 10px 12px;">Category</th>
                                <th style="padding: 10px 12px;">Astraea First-Party Replacement</th>
                                <th style="padding: 10px 12px;">Security & Performance Benefit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($analysis['replaceable'] as $item): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                    <td style="padding: 12px; font-weight: 600; color: #f1f5f9;">
                                        <?php echo htmlspecialchars($item['plugin_slug'], ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding: 12px; color: #94a3b8;">
                                        <?php echo htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span style="color: #38bdf8; font-weight: 600;">
                                            <?php echo htmlspecialchars($item['module_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px; color: #94a3b8; font-size: 12px;">
                                        <?php echo htmlspecialchars($item['benefit'], ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Execution Action -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 24px; text-align: right;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="astraea_execute_migration">
                    <input type="hidden" name="_astraea_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <button type="submit" class="button button-primary" style="background: #0284c7; border: 1px solid #38bdf8; font-size: 14px; padding: 8px 24px; border-radius: 6px; cursor: pointer;" onclick="return confirm('Execute migration with automatic pre-migration Vault snapshot?');">
                        Execute Safe Migration (Pre-Flight + Vault Snapshot)
                    </button>
                </form>
            </div>
        </div>
        <?php
    }
}
