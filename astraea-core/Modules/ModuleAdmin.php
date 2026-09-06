<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules;

use Astraea\Auth\StepUpAuthService;
use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;

/**
 * Administration Screen & Controller for Astraea Module Fabric.
 *
 * @package Astraea\Modules
 */
final class ModuleAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_astraea_toggle_module', [self::class, 'handleToggleAction']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'index.php',
            'Astraea Modules — First-Party Kernel Fabric',
            'Modules',
            'manage_options',
            'astraea-modules',
            [self::class, 'renderScreen']
        );
    }

    public static function handleToggleAction(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized capability.', 'Astraea Module Fabric', ['response' => 403]);
        }

        check_admin_referer('astraea_toggle_module', '_astraea_module_nonce');

        $moduleId = sanitize_key($_POST['module_id'] ?? '');
        $action = sanitize_key($_POST['module_action'] ?? '');

        if ($moduleId === '' || !in_array($action, ['enable', 'disable'], true)) {
            wp_safe_redirect(admin_url('admin.php?page=astraea-modules&error=invalid_request'));
            exit;
        }

        $module = ModuleRegistry::get($moduleId);
        if ($module === null) {
            wp_safe_redirect(admin_url('admin.php?page=astraea-modules&error=unknown_module'));
            exit;
        }

        // Step-Up authentication check for sensitive changes
        if (class_exists(StepUpAuthService::class) && !StepUpAuthService::isCurrentSessionVerified()) {
            wp_safe_redirect(StepUpAuthService::getVerificationUrl(admin_url('admin.php?page=astraea-modules')));
            exit;
        }

        try {
            if ($action === 'enable') {
                ModuleRegistry::setModuleEnabled($moduleId, true);
            } else {
                ModuleRegistry::setModuleEnabled($moduleId, false);
            }
            wp_safe_redirect(admin_url('admin.php?page=astraea-modules&status=updated'));
        } catch (\Throwable $e) {
            error_log('[SEC] Module toggle error: ' . $e->getMessage());
            wp_safe_redirect(admin_url('admin.php?page=astraea-modules&error=action_failed'));
        }
        exit;
    }

    public static function renderScreen(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', 'Astraea Module Fabric', ['response' => 403]);
        }

        $allModules = ModuleRegistry::getAll();
        $moduleCount = count($allModules);
        $activeCount = 0;
        $blockedCount = 0;
        $disabledCount = 0;

        foreach ($allModules as $id => $mod) {
            $st = ModuleRegistry::getState($id);
            if ($st === ModuleState::ACTIVE) {
                $activeCount++;
            } elseif ($st === ModuleState::BLOCKED || $st === ModuleState::FAILED) {
                $blockedCount++;
            } elseif ($st === ModuleState::DISABLED) {
                $disabledCount++;
            }
        }

        $nonce = wp_create_nonce('astraea_toggle_module');
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 1200px; margin: 24px auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <div>
                    <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea Module Fabric</h1>
                    <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">First-party decoupled kernel subsystems — WordPress without the Plugin Stack.</p>
                </div>
                <div style="display: flex; gap: 12px;">
                    <span class="astraea-badge" style="background: rgba(14, 165, 233, 0.15); color: #38bdf8; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 12px; border: 1px solid rgba(56, 189, 248, 0.3);">
                        Active: <?php echo (int)$activeCount; ?> / <?php echo (int)$moduleCount; ?>
                    </span>
                    <?php if ($blockedCount > 0): ?>
                    <span class="astraea-badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 12px; border: 1px solid rgba(248, 113, 113, 0.3);">
                        Blocked/Failed: <?php echo (int)$blockedCount; ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; color: #cbd5e1;">
                    <thead>
                        <tr style="background: rgba(30, 41, 59, 0.7); border-bottom: 1px solid rgba(255, 255, 255, 0.08); color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: 0.05em;">
                            <th style="padding: 14px 18px;"><?php echo esc_html(function_exists('astraea_t') ? astraea_t('nav_modules') : 'Module'); ?></th>
                            <th style="padding: 14px 18px;">Phase</th>
                            <th style="padding: 14px 18px;">Dependencies</th>
                            <th style="padding: 14px 18px;">State</th>
                            <th style="padding: 14px 18px;"><?php echo esc_html(function_exists('astraea_t') ? astraea_t('nav_health') : 'Health Probe'); ?></th>
                            <th style="padding: 14px 18px; text-align: right;"><?php echo esc_html(function_exists('astraea_t') ? astraea_t('action_apply') : 'Action'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allModules)): ?>
                            <tr>
                                <td colspan="6" style="padding: 24px; text-align: center; color: #64748b;">
                                    No modules currently registered in Module Fabric.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allModules as $id => $module):
                                $desc = $module->descriptor();
                                $state = ModuleRegistry::getState($id);
                                $lastErr = ModuleRegistry::getLastError($id);
                                $health = $state === ModuleState::ACTIVE ? $module->probeHealth() : ModuleHealth::unknown('Not Active', 'Module is not in active state');

                                $modTitleKey = 'module_' . $desc->id . '_title';
                                $modDescKey  = 'module_' . $desc->id . '_desc';
                                $hasTitle = function_exists('astraea_has_t')
                                    ? astraea_has_t($modTitleKey)
                                    : (method_exists(\Astraea\I18n\I18n::class, 'has') && \Astraea\I18n\I18n::has($modTitleKey));
                                $hasDesc = function_exists('astraea_has_t')
                                    ? astraea_has_t($modDescKey)
                                    : (method_exists(\Astraea\I18n\I18n::class, 'has') && \Astraea\I18n\I18n::has($modDescKey));

                                $modTitle = ($hasTitle && function_exists('astraea_t'))
                                    ? astraea_t($modTitleKey)
                                    : $desc->name;
                                $modDesc = ($hasDesc && function_exists('astraea_t'))
                                    ? astraea_t($modDescKey)
                                    : $desc->description;
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.04);">
                                <td style="padding: 16px 18px;">
                                    <div style="font-weight: 600; color: #f1f5f9;"><?php echo htmlspecialchars($modTitle, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                        ID: <code><?php echo htmlspecialchars($desc->id, ENT_QUOTES, 'UTF-8'); ?></code> &bull; v<?php echo htmlspecialchars($desc->version, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;"><?php echo htmlspecialchars($modDesc, ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td style="padding: 16px 18px;">
                                    <span style="font-family: monospace; font-size: 11px; background: rgba(255, 255, 255, 0.05); padding: 3px 8px; border-radius: 4px;">
                                        <?php echo htmlspecialchars($desc->bootPhase->value, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td style="padding: 16px 18px; font-size: 12px;">
                                    <?php if (!empty($desc->dependencies)): ?>
                                        <?php foreach ($desc->dependencies as $dep): ?>
                                            <span style="display: inline-block; background: rgba(56, 189, 248, 0.1); color: #38bdf8; padding: 2px 6px; border-radius: 4px; margin: 2px; font-size: 11px;">
                                                <?php echo htmlspecialchars($dep, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span style="color: #64748b;">None (L0)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 16px 18px;">
                                    <?php
                                    $pillColor = match ($state) {
                                        ModuleState::ACTIVE   => 'background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 1px solid rgba(74, 222, 128, 0.3);',
                                        ModuleState::DISABLED => 'background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.3);',
                                        ModuleState::BLOCKED  => 'background: rgba(234, 179, 8, 0.15); color: #facc15; border: 1px solid rgba(250, 204, 21, 0.3);',
                                        ModuleState::FAILED   => 'background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.3);',
                                        default               => 'background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(96, 165, 250, 0.3);',
                                    };
                                    ?>
                                    <span style="display: inline-block; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; <?php echo $pillColor; ?>">
                                        <?php echo htmlspecialchars($state->value, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <?php if ($lastErr !== null): ?>
                                        <div style="font-size: 11px; color: #f87171; margin-top: 4px; max-width: 200px;">
                                            <?php echo htmlspecialchars($lastErr, ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 16px 18px;">
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?php echo $health->status === ModuleHealth::STATUS_HEALTHY ? '#4ade80' : ($health->status === ModuleHealth::STATUS_DEGRADED ? '#facc15' : ($health->status === ModuleHealth::STATUS_CRITICAL ? '#f87171' : '#94a3b8')); ?>;"></span>
                                        <span style="font-weight: 600; font-size: 12px; color: #e2e8f0;"><?php echo htmlspecialchars($health->label, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">
                                        <?php echo htmlspecialchars($health->message, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </td>
                                <td style="padding: 16px 18px; text-align: right;">
                                    <?php if ($desc->isToggleable): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block;">
                                            <input type="hidden" name="action" value="astraea_toggle_module">
                                            <input type="hidden" name="_astraea_module_nonce" value="<?php echo esc_attr($nonce); ?>">
                                            <input type="hidden" name="module_id" value="<?php echo esc_attr($desc->id); ?>">
                                            <?php if ($state === ModuleState::ACTIVE): ?>
                                                <input type="hidden" name="module_action" value="disable">
                                                <button type="submit" class="button" style="background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); font-size: 12px; padding: 2px 10px; border-radius: 6px; cursor: pointer;">
                                                    <?php echo esc_html(function_exists('astraea_t') ? astraea_t('status_disabled') : 'Disable'); ?>
                                                </button>
                                            <?php else: ?>
                                                <input type="hidden" name="module_action" value="enable">
                                                <button type="submit" class="button button-primary" style="background: #0284c7; border: 1px solid #38bdf8; font-size: 12px; padding: 2px 10px; border-radius: 6px; cursor: pointer;">
                                                    <?php echo esc_html(function_exists('astraea_t') ? astraea_t('status_enabled') : 'Enable'); ?>
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size: 11px; color: #64748b; font-style: italic;"><?php echo esc_html(function_exists('astraea_t') ? astraea_t('status_locked') . ' (Core)' : 'Locked (Core)'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
