<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Tasks;

/**
 * Administration Screen for Astraea Task Center.
 *
 * @package Astraea\Tasks
 */
final class TasksAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_astraea_run_task', [self::class, 'handleRunTask']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'tools.php',
            'Astraea Task Center',
            'Task Center',
            'manage_options',
            'astraea-tasks',
            [self::class, 'renderScreen']
        );
    }

    public static function handleRunTask(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'Tasks', ['response' => 403]);
        }

        check_admin_referer('astraea_task_action', '_astraea_nonce');

        $hook = sanitize_text_field($_POST['hook'] ?? '');
        if ($hook !== '') {
            TaskInspector::runNow($hook);
        }

        wp_safe_redirect(admin_url('tools.php?page=astraea-tasks&status=dispatched'));
        exit;
    }

    public static function renderScreen(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', 'Tasks', ['response' => 403]);
        }

        $tasks = TaskInspector::getTasks();
        $total = count($tasks);
        $overdueCount = 0;
        $coreCount = 0;

        foreach ($tasks as $t) {
            if ($t['is_overdue']) {
                $overdueCount++;
            }
            if ($t['source'] === 'Astraea Core') {
                $coreCount++;
            }
        }

        $nonce = wp_create_nonce('astraea_task_action');
        $now = time();
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 1100px; margin: 24px auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea Task Center</h1>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">Unified WP-Cron and Astraea task scheduler with backlog diagnostics.</p>
            </div>

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px;">
                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Total Scheduled Tasks</div>
                    <div style="font-size: 28px; font-weight: 700; color: #38bdf8; margin: 8px 0 4px;"><?php echo (int)$total; ?></div>
                    <div style="font-size: 12px; color: #64748b;">Registered event hooks</div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Overdue Tasks</div>
                    <div style="font-size: 28px; font-weight: 700; color: <?php echo $overdueCount > 0 ? '#f87171' : '#4ade80'; ?>; margin: 8px 0 4px;"><?php echo (int)$overdueCount; ?></div>
                    <div style="font-size: 12px; color: #64748b;">Delayed > 10 minutes</div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Astraea First-Party Tasks</div>
                    <div style="font-size: 28px; font-weight: 700; color: #4ade80; margin: 8px 0 4px;"><?php echo (int)$coreCount; ?></div>
                    <div style="font-size: 12px; color: #64748b;">Kernel maintenance hooks</div>
                </div>
            </div>

            <!-- Task List Table -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; color: #cbd5e1;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); color: #94a3b8; font-size: 11px; text-transform: uppercase;">
                            <th style="padding: 10px 12px;">Hook Name</th>
                            <th style="padding: 10px 12px;">Source</th>
                            <th style="padding: 10px 12px;">Recurrence</th>
                            <th style="padding: 10px 12px;">Next Execution</th>
                            <th style="padding: 10px 12px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tasks)): ?>
                            <tr><td colspan="5" style="padding: 16px; text-align: center; color: #64748b;">No scheduled tasks found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($tasks as $task):
                                $diff = $task['next_run'] - $now;
                                $timeStr = ($diff <= 0) ? abs($diff) . 's overdue' : 'in ' . human_time_diff($now, $task['next_run']);
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                <td style="padding: 10px 12px; font-family: monospace; font-size: 12px; color: #f1f5f9;">
                                    <?php echo htmlspecialchars($task['hook'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($task['is_overdue']): ?>
                                        <span style="display: inline-block; background: rgba(239, 68, 68, 0.2); color: #fca5a5; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px; margin-left: 6px;">OVERDUE</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 12px;">
                                    <span style="background: rgba(255,255,255,0.05); padding: 3px 8px; border-radius: 4px; font-size: 11px; color: <?php echo $task['source'] === 'Astraea Core' ? '#38bdf8' : '#94a3b8'; ?>;">
                                        <?php echo htmlspecialchars($task['source'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td style="padding: 10px 12px; color: #94a3b8; font-size: 12px;">
                                    <?php echo htmlspecialchars($task['recurrence'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="padding: 10px 12px; font-size: 12px; color: <?php echo $task['is_overdue'] ? '#f87171' : '#cbd5e1'; ?>;">
                                    <?php echo esc_html($timeStr); ?>
                                    <div style="font-size: 10px; color: #64748b;"><?php echo esc_html(date_i18n('Y-m-d H:i:s', $task['next_run'])); ?></div>
                                </td>
                                <td style="padding: 10px 12px; text-align: right;">
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="astraea_run_task">
                                        <input type="hidden" name="_astraea_nonce" value="<?php echo esc_attr($nonce); ?>">
                                        <input type="hidden" name="hook" value="<?php echo esc_attr($task['hook']); ?>">
                                        <button type="submit" class="button" style="background: rgba(14, 165, 233, 0.2); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.4); font-size: 11px; padding: 2px 10px; border-radius: 4px; cursor: pointer;">
                                            Run Now
                                        </button>
                                    </form>
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
