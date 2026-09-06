<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Redirects;

/**
 * Administration Screen for Astraea Redirect Manager.
 *
 * @package Astraea\Redirects
 */
final class RedirectAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_astraea_save_redirect', [self::class, 'handleSaveRedirect']);
        add_action('admin_post_astraea_delete_redirect', [self::class, 'handleDeleteRedirect']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'tools.php',
            'Astraea Redirect Manager',
            'Redirects',
            'manage_options',
            'astraea-redirects',
            [self::class, 'renderScreen']
        );
    }

    public static function handleSaveRedirect(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'Redirects', ['response' => 403]);
        }

        check_admin_referer('astraea_redirect_save', '_astraea_nonce');

        $source = sanitize_text_field($_POST['source'] ?? '');
        $target = sanitize_text_field($_POST['target'] ?? '');
        $code = (int)($_POST['code'] ?? 301);
        $type = sanitize_key($_POST['match_type'] ?? 'exact');

        try {
            $rule = RedirectRule::fromArray([
                'source'     => $source,
                'target'     => $target,
                'code'       => $code,
                'match_type' => $type,
                'active'     => true,
            ]);
            RedirectEngine::saveRule($rule);
            wp_safe_redirect(admin_url('tools.php?page=astraea-redirects&status=saved'));
        } catch (\Throwable $e) {
            wp_safe_redirect(admin_url('tools.php?page=astraea-redirects&error=' . urlencode($e->getMessage())));
        }
        exit;
    }

    public static function handleDeleteRedirect(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'Redirects', ['response' => 403]);
        }

        check_admin_referer('astraea_redirect_delete', '_astraea_nonce');
        $id = sanitize_key($_POST['rule_id'] ?? '');

        if ($id !== '') {
            RedirectEngine::deleteRule($id);
        }

        wp_safe_redirect(admin_url('tools.php?page=astraea-redirects&status=deleted'));
        exit;
    }

    public static function renderScreen(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', 'Redirects', ['response' => 403]);
        }

        $rules = RedirectEngine::getRules();
        $logs = NotFoundMonitor::getLogs();
        $saveNonce = wp_create_nonce('astraea_redirect_save');
        $delNonce = wp_create_nonce('astraea_redirect_delete');
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 1000px; margin: 24px auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea Redirect Manager</h1>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">ReDoS-immune URL forwarding, loop detection, and privacy-safe 404 monitoring.</p>
            </div>

            <!-- Add Redirect Form -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                <h3 style="color: #f8fafc; margin: 0 0 14px; font-size: 15px;">Create Redirect Rule</h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: grid; grid-template-columns: 2fr 2fr 1fr 1fr auto; gap: 12px; align-items: end;">
                    <input type="hidden" name="action" value="astraea_save_redirect">
                    <input type="hidden" name="_astraea_nonce" value="<?php echo esc_attr($saveNonce); ?>">
                    <div>
                        <label style="display: block; font-size: 11px; color: #94a3b8; margin-bottom: 4px; font-weight: 600;">Source Path</label>
                        <input type="text" name="source" placeholder="/old-path" required style="width: 100%; padding: 6px 10px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 11px; color: #94a3b8; margin-bottom: 4px; font-weight: 600;">Target URL</label>
                        <input type="text" name="target" placeholder="/new-destination" required style="width: 100%; padding: 6px 10px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 11px; color: #94a3b8; margin-bottom: 4px; font-weight: 600;">HTTP Code</label>
                        <select name="code" style="width: 100%; padding: 6px 10px; background: rgba(30,41,59,0.8); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff;">
                            <option value="301">301 (Permanent)</option>
                            <option value="302">302 (Found)</option>
                            <option value="307">307 (Temporary)</option>
                            <option value="308">308 (Permanent Redirect)</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 11px; color: #94a3b8; margin-bottom: 4px; font-weight: 600;">Match Type</label>
                        <select name="match_type" style="width: 100%; padding: 6px 10px; background: rgba(30,41,59,0.8); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff;">
                            <option value="exact">Exact</option>
                            <option value="wildcard">Wildcard (*)</option>
                            <option value="regex">Safe Regex</option>
                        </select>
                    </div>
                    <button type="submit" class="button button-primary" style="background: #0284c7; border: 1px solid #38bdf8; padding: 6px 16px; border-radius: 6px; cursor: pointer;">
                        Add Rule
                    </button>
                </form>
            </div>

            <!-- Active Redirect Rules Table -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                <h3 style="color: #f8fafc; margin: 0 0 14px; font-size: 15px;">Active Rules (<?php echo count($rules); ?>)</h3>
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; color: #cbd5e1;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); color: #94a3b8; font-size: 11px; text-transform: uppercase;">
                            <th style="padding: 10px 12px;">Source</th>
                            <th style="padding: 10px 12px;">Target</th>
                            <th style="padding: 10px 12px;">Type</th>
                            <th style="padding: 10px 12px;">Match</th>
                            <th style="padding: 10px 12px;">Hits</th>
                            <th style="padding: 10px 12px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rules)): ?>
                            <tr><td colspan="6" style="padding: 16px; text-align: center; color: #64748b;">No redirect rules registered.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rules as $r): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                    <td style="padding: 10px 12px; font-family: monospace; color: #38bdf8;"><?php echo htmlspecialchars($r->source, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding: 10px 12px; font-family: monospace; color: #e2e8f0;"><?php echo htmlspecialchars($r->target, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding: 10px 12px;"><span style="background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 4px; font-size: 11px;"><?php echo (int)$r->code; ?></span></td>
                                    <td style="padding: 10px 12px; color: #94a3b8;"><?php echo htmlspecialchars($r->matchType, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding: 10px 12px; font-family: monospace;"><?php echo (int)$r->hits; ?></td>
                                    <td style="padding: 10px 12px; text-align: right;">
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="astraea_delete_redirect">
                                            <input type="hidden" name="_astraea_nonce" value="<?php echo esc_attr($delNonce); ?>">
                                            <input type="hidden" name="rule_id" value="<?php echo esc_attr($r->id); ?>">
                                            <button type="submit" style="background: none; border: none; color: #f87171; cursor: pointer; font-size: 12px;">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 404 Monitor Table -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                <h3 style="color: #f8fafc; margin: 0 0 14px; font-size: 15px;">Privacy-Safe 404 Log (Top Unmatched URIs)</h3>
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; color: #cbd5e1;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); color: #94a3b8; font-size: 11px; text-transform: uppercase;">
                            <th style="padding: 10px 12px;">Requested Path</th>
                            <th style="padding: 10px 12px;">Hits</th>
                            <th style="padding: 10px 12px;">Last Seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="3" style="padding: 16px; text-align: center; color: #64748b;">No recent 404 events recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $item): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                    <td style="padding: 10px 12px; font-family: monospace; color: #f87171;"><?php echo htmlspecialchars($item['path'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding: 10px 12px; font-family: monospace;"><?php echo (int)$item['hits']; ?></td>
                                    <td style="padding: 10px 12px; color: #94a3b8; font-size: 12px;"><?php echo esc_html(date_i18n('Y-m-d H:i', (int)$item['last_seen'])); ?></td>
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
