<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Admin;

use Astraea\Vault\Config;
use Astraea\Vault\Crypto\KeyManager;
use Astraea\Vault\Notification\Notifier;
use Astraea\Vault\Repository\BackupRepository;
use Astraea\Vault\Repository\IncidentRepository;

final class Admin
{
    public function __construct(
        private readonly KeyManager $keys,
        private readonly BackupRepository $backups,
        private readonly IncidentRepository $incidents,
        private readonly Notifier $notifier
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void
    {
        add_menu_page(
            __('Astraea Vault', 'astraea-vault'),
            __('Astraea Vault', 'astraea-vault'),
            'manage_astraea_vault',
            'astraea-vault',
            [$this, 'render'],
            'dashicons-shield-alt',
            58
        );
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_astraea-vault') { return; }
        wp_enqueue_style('astraea-vault-admin', ASTRAEA_VAULT_URL . 'assets/admin.css', [], ASTRAEA_VAULT_VERSION);
        wp_enqueue_script('astraea-vault-admin', ASTRAEA_VAULT_URL . 'assets/admin.js', [], ASTRAEA_VAULT_VERSION, true);
    }

    public function render(): void
    {
        if (!current_user_can('manage_astraea_vault')) {
            wp_die(esc_html__('You are not allowed to manage Astraea Vault.', 'astraea-vault'));
        }
        $initialized = $this->keys->initialized();
        $backups = $initialized ? $this->backups->all(100) : [];
        $incidents = $initialized ? $this->incidents->recent(20) : [];
        $settings = Config::settings();
        $notices = $this->notifier->notices();
        $status = isset($_GET['av_status']) ? sanitize_key(wp_unslash($_GET['av_status'])) : '';
        $message = isset($_GET['av_message']) ? sanitize_text_field(wp_unslash($_GET['av_message'])) : '';
        ?>
        <div class="wrap av-wrap">
            <header class="av-hero">
                <div>
                    <div class="av-eyebrow">ASTRAEAOS WP / CONTINUITY ENGINE</div>
                    <h1>Astraea Vault</h1>
                    <p>Encrypted Backup · Transactional Updates · Autonomous Recovery</p>
                </div>
                <div class="av-status-pill <?php echo $initialized ? 'is-ok' : 'is-warn'; ?>">
                    <span class="av-dot"></span><?php echo $initialized ? esc_html__('Vault armed', 'astraea-vault') : esc_html__('Setup required', 'astraea-vault'); ?>
                </div>
            </header>

            <?php if ($status !== ''): ?>
                <div class="av-banner <?php echo $status === 'ok' ? 'is-ok' : ($status === 'warn' ? 'is-warn' : 'is-error'); ?>">
                    <?php echo esc_html($message !== '' ? $message : ($status === 'ok' ? 'Operation completed.' : 'Operation failed.')); ?>
                </div>
            <?php endif; ?>

            <?php foreach ($notices as $notice): ?>
                <div class="av-banner <?php echo esc_attr(($notice['type'] ?? '') === 'success' ? 'is-ok' : (($notice['type'] ?? '') === 'warning' ? 'is-warn' : (($notice['type'] ?? '') === 'error' ? 'is-error' : ''))); ?>">
                    <strong><?php echo esc_html((string)($notice['title'] ?? 'Astraea Vault')); ?></strong>
                    <span><?php echo nl2br(esc_html((string)($notice['message'] ?? ''))); ?></span>
                </div>
            <?php endforeach; ?>

            <?php if (!$initialized): ?>
                <?php $this->renderSetup(); ?>
            <?php else: ?>
                <?php $this->renderOverview($backups, $incidents, $settings); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderSetup(): void
    {
        ?>
        <section class="av-panel av-setup">
            <div class="av-panel-head"><div><div class="av-kicker">ZERO-TRUST KEYRING</div><h2>Initialize Astraea Vault</h2></div><span class="av-chip">AES-256-GCM · Argon2id</span></div>
            <p>The passphrase protects the Vault Master Key. Automatic backups use a separate service-key slot; the passphrase itself is never stored.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="av-form-grid">
                <input type="hidden" name="action" value="astraea_vault_initialize">
                <?php wp_nonce_field('astraea_vault_initialize'); ?>
                <label><span>Vault passphrase</span><input type="password" name="passphrase" minlength="16" maxlength="4096" autocomplete="new-password" required></label>
                <label><span>Confirm passphrase</span><input type="password" name="passphrase_confirm" minlength="16" maxlength="4096" autocomplete="new-password" required></label>
                <div class="av-form-actions"><button class="button button-primary av-btn av-primary" type="submit">Initialize Vault</button></div>
            </form>
        </section>
        <?php
    }

    private function renderOverview(array $backups, array $incidents, array $settings): void
    {
        $last = $backups[0] ?? null;
        $verified = count(array_filter($backups, static fn(array $b): bool => ($b['status'] ?? '') === 'verified'));
        $storageBytes = array_sum(array_map(static fn(array $b): int => (int)($b['size_bytes'] ?? 0), $backups));
        ?>
        <section class="av-metrics">
            <?php $this->metric('Protection', 'AES-256-GCM', 'Authenticated encryption'); ?>
            <?php $this->metric('Verified backups', (string)$verified, $last ? 'Last: ' . (string)$last['created_at'] . ' UTC' : 'No backup yet'); ?>
            <?php $this->metric('Storage', size_format($storageBytes, 2), count($backups) . ' tracked archives'); ?>
            <?php $this->metric('Update Guard', !empty($settings['update_guard']) ? 'ARMED' : 'OFF', !empty($settings['auto_rollback']) ? 'Auto rollback armed' : 'Manual recovery'); ?>
        </section>

        <div class="av-grid-2">
            <section class="av-panel">
                <div class="av-panel-head"><div><div class="av-kicker">BACKUP CONTROL</div><h2>Create verified backup</h2></div></div>
                <div class="av-action-row">
                    <?php $this->backupButton('full', 'Full Site Backup'); ?>
                    <?php $this->backupButton('database', 'Database Backup'); ?>
                </div>
                <p class="description">Every backup is streamed, encrypted, finalized and cryptographically verified before it receives VERIFIED status.</p>
            </section>

            <section class="av-panel">
                <div class="av-panel-head"><div><div class="av-kicker">AUTOMATION</div><h2>Backup policy</h2></div></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="av-form-grid compact">
                    <input type="hidden" name="action" value="astraea_vault_settings">
                    <?php wp_nonce_field('astraea_vault_settings'); ?>
                    <label><span>Schedule</span><select name="schedule">
                        <?php foreach (['off'=>'Off','hourly'=>'Hourly','twicedaily'=>'Twice daily','daily'=>'Daily','astraea_weekly'=>'Weekly'] as $value=>$label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected((string)$settings['schedule'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label><span>Scheduled type</span><select name="scheduled_type"><option value="full" <?php selected($settings['scheduled_type'], 'full'); ?>>Full site</option><option value="database" <?php selected($settings['scheduled_type'], 'database'); ?>>Database</option></select></label>
                    <label><span>Retention</span><input type="number" min="1" max="365" name="retention" value="<?php echo esc_attr((string)$settings['retention']); ?>"></label>
                    <div class="av-switches">
                        <?php $this->checkbox('update_guard', 'Snapshot before plugin updates', !empty($settings['update_guard'])); ?>
                        <?php $this->checkbox('strict_update_guard', 'Block update if snapshot fails', !empty($settings['strict_update_guard'])); ?>
                        <?php $this->checkbox('auto_rollback', 'Automatic plugin rollback', !empty($settings['auto_rollback'])); ?>
                        <?php $this->checkbox('email_notifications', 'Email recovery notifications', !empty($settings['email_notifications'])); ?>
                    </div>
                    <div class="av-form-actions"><button class="button av-btn" type="submit">Save policy</button></div>
                </form>
            </section>
        </div>

        <section class="av-panel">
            <div class="av-panel-head"><div><div class="av-kicker">ENCRYPTED ARCHIVES</div><h2>Backups</h2></div><span class="av-chip"><?php echo esc_html((string)count($backups)); ?> archives</span></div>
            <div class="av-table-wrap"><table class="widefat fixed striped av-table"><thead><tr><th>Created</th><th>Type</th><th>Status</th><th>Size</th><th>Trigger</th><th>Actions</th></tr></thead><tbody>
            <?php if ($backups === []): ?><tr><td colspan="6">No backups created yet.</td></tr><?php endif; ?>
            <?php foreach ($backups as $backup): ?>
                <tr>
                    <td><code><?php echo esc_html((string)$backup['created_at']); ?> UTC</code></td>
                    <td><?php echo esc_html(strtoupper((string)$backup['type'])); ?></td>
                    <td><span class="av-chip <?php echo ($backup['status'] ?? '') === 'verified' ? 'is-ok' : 'is-warn'; ?>"><?php echo esc_html((string)$backup['status']); ?></span></td>
                    <td><?php echo esc_html(size_format((int)$backup['size_bytes'], 2)); ?></td>
                    <td><?php echo esc_html((string)$backup['trigger_source']); ?></td>
                    <td class="av-actions-cell">
                        <?php $this->smallAction('astraea_vault_verify', (string)$backup['id'], 'Verify'); ?>
                        <?php $this->downloadAction((string)$backup['id']); ?>
                        <?php if (($backup['type'] ?? '') !== 'plugin'): $this->restoreAction((string)$backup['id'], (string)$backup['type']); endif; ?>
                        <?php $this->deleteAction((string)$backup['id']); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>

        <div class="av-grid-2">
            <section class="av-panel">
                <div class="av-panel-head"><div><div class="av-kicker">RECOVERY INCIDENTS</div><h2>Recent failures</h2></div></div>
                <?php if ($incidents === []): ?><div class="av-empty">No recovery incidents recorded.</div><?php else: ?>
                    <div class="av-incident-list">
                    <?php foreach ($incidents as $incident): ?>
                        <article class="av-incident"><div><strong><?php echo esc_html((string)$incident['plugin']); ?></strong><span><?php echo esc_html((string)$incident['error_type']); ?> · line <?php echo esc_html((string)$incident['error_line']); ?></span></div><code><?php echo esc_html((string)$incident['created_at']); ?> UTC</code><p><?php echo esc_html((string)$incident['error_message']); ?></p></article>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="av-panel">
                <div class="av-panel-head"><div><div class="av-kicker">KEY MANAGEMENT</div><h2>Rotate Vault passphrase</h2></div></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="av-form-grid compact">
                    <input type="hidden" name="action" value="astraea_vault_change_passphrase">
                    <?php wp_nonce_field('astraea_vault_change_passphrase'); ?>
                    <label><span>Current passphrase</span><input type="password" name="old_passphrase" autocomplete="current-password" required></label>
                    <label><span>New passphrase</span><input type="password" name="new_passphrase" minlength="16" maxlength="4096" autocomplete="new-password" required></label>
                    <label><span>Confirm new passphrase</span><input type="password" name="new_passphrase_confirm" minlength="16" maxlength="4096" autocomplete="new-password" required></label>
                    <div class="av-form-actions"><button class="button av-btn" type="submit">Rotate passphrase</button></div>
                </form>
            </section>
        </div>
        <?php
    }

    private function metric(string $label, string $value, string $sub): void
    {
        ?><article class="av-metric"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html($value); ?></strong><small><?php echo esc_html($sub); ?></small></article><?php
    }

    private function backupButton(string $type, string $label): void
    {
        ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="astraea_vault_backup"><input type="hidden" name="type" value="<?php echo esc_attr($type); ?>"><?php wp_nonce_field('astraea_vault_backup_' . $type); ?><button class="button button-primary av-btn av-primary" type="submit"><?php echo esc_html($label); ?></button></form><?php
    }

    private function checkbox(string $name, string $label, bool $checked): void
    {
        ?><label class="av-check"><input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($checked); ?>><span><?php echo esc_html($label); ?></span></label><?php
    }

    private function smallAction(string $action, string $id, string $label, bool $danger = false): void
    {
        ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" <?php echo $danger ? 'data-confirm="Delete this encrypted backup permanently?"' : ''; ?>><input type="hidden" name="action" value="<?php echo esc_attr($action); ?>"><input type="hidden" name="backup_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field($action . '_' . $id); ?><button class="button button-small av-mini <?php echo $danger ? 'is-danger' : ''; ?>" type="submit"><?php echo esc_html($label); ?></button></form><?php
    }

    private function downloadAction(string $id): void
    {
        ?><details class="av-restore"><summary class="button button-small av-mini">Download</summary><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="astraea_vault_download"><input type="hidden" name="backup_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field('astraea_vault_download_' . $id); ?><input type="password" name="passphrase" autocomplete="current-password" placeholder="Vault passphrase" required><button class="button av-mini" type="submit">Authorize download</button></form></details><?php
    }

    private function deleteAction(string $id): void
    {
        ?><details class="av-restore av-danger-action"><summary class="button button-small av-mini is-danger">Delete</summary><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-confirm="Delete this encrypted backup permanently?"><input type="hidden" name="action" value="astraea_vault_delete"><input type="hidden" name="backup_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field('astraea_vault_delete_' . $id); ?><input type="password" name="passphrase" autocomplete="current-password" placeholder="Vault passphrase" required><button class="button av-mini is-danger" type="submit">Permanently delete</button></form></details><?php
    }

    private function restoreAction(string $id, string $type): void
    {
        ?><details class="av-restore"><summary class="button button-small av-mini">Restore</summary><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="astraea_vault_restore"><input type="hidden" name="backup_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field('astraea_vault_restore_' . $id); ?><?php if ($type === 'database'): ?><input type="hidden" name="scope" value="database"><span class="av-form-note">Database-only restore</span><?php else: ?><select name="scope"><option value="full">Full</option><option value="database">Database</option><option value="files">Files</option></select><?php endif; ?><input type="password" name="passphrase" autocomplete="current-password" placeholder="Vault passphrase" required><button class="button av-mini" type="submit">Confirm restore</button></form></details><?php
    }
}
