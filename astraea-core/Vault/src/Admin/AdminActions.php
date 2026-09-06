<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Admin;

use Astraea\Vault\Backup\BackupService;
use Astraea\Vault\Backup\VerifyService;
use Astraea\Vault\Config;
use Astraea\Vault\Crypto\CryptoService;
use Astraea\Vault\Crypto\KeyManager;
use Astraea\Vault\Exception\SecurityException;
use Astraea\Vault\Exception\StorageException;
use Astraea\Vault\Exception\ValidationException;
use Astraea\Vault\Repository\BackupRepository;
use Astraea\Vault\Integration\AstraeaCore;
use Astraea\Vault\Notification\Notifier;
use Astraea\Vault\Restore\RestoreService;
use Astraea\Vault\Scheduler\Scheduler;
use Astraea\Vault\Security\PassphraseRateLimiter;
use Astraea\Vault\Storage\LocalStorage;
use Astraea\Vault\Support\PhpErrorGuard;

final class AdminActions
{
    public function __construct(
        private readonly KeyManager $keys,
        private readonly CryptoService $crypto,
        private readonly BackupService $backups,
        private readonly VerifyService $verifier,
        private readonly RestoreService $restore,
        private readonly BackupRepository $repository,
        private readonly LocalStorage $storage,
        private readonly PassphraseRateLimiter $rateLimiter,
        private readonly Notifier $notifier
    ) {}

    public function register(): void
    {
        foreach (['initialize','backup','verify','restore','delete','download','settings','change_passphrase'] as $action) {
            add_action('admin_post_astraea_vault_' . $action, [$this, $action]);
        }
    }

    public function initialize(): void
    {
        $this->authorize('manage_astraea_vault', 'astraea_vault_initialize');
        $this->requireStepUp('vault:initialize');
        $pass = isset($_POST['passphrase']) ? (string)wp_unslash($_POST['passphrase']) : '';
        $confirm = isset($_POST['passphrase_confirm']) ? (string)wp_unslash($_POST['passphrase_confirm']) : '';
        try {
            if (!hash_equals($pass, $confirm)) { throw new ValidationException('Passphrases do not match.'); }
            $recovery = PhpErrorGuard::run(fn(): string => $this->keys->initialize($pass));
            $this->renderRecoveryKey($recovery);
        } catch (ValidationException $e) {
            $this->redirect('error', $e->getMessage());
        } catch (SecurityException $e) {
            AstraeaCore::log('security', 'Security boundary rejected an admin operation.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Request rejected for security reasons.');
        } catch (StorageException $e) {
            AstraeaCore::log('error', 'Vault storage operation failed.', ['exception' => get_class($e)]);
            $this->redirect('error', 'A server error occurred.');
        } catch (\Throwable $e) {
            AstraeaCore::log('critical', 'Vault admin operation faulted.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Critical system fault.');
        }
    }

    public function backup(): void
    {
        $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
        if (!in_array($type, ['full','database'], true)) { $type = 'invalid'; }
        $this->authorize('manage_astraea_vault', 'astraea_vault_backup_' . $type);
        $master = '';
        try {
            $master = $this->keys->unlockWithServiceKey();
            PhpErrorGuard::run(fn() => $this->backups->create($type, $master, 'manual'));
            $this->redirect('ok', 'Encrypted backup created and verified.');
        } catch (ValidationException $e) {
            $this->redirect('error', $e->getMessage());
        } catch (SecurityException $e) {
            AstraeaCore::log('security', 'Security boundary rejected an admin operation.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Request rejected for security reasons.');
        } catch (StorageException $e) {
            AstraeaCore::log('error', 'Vault storage operation failed.', ['exception' => get_class($e)]);
            $this->redirect('error', 'A server error occurred.');
        } catch (\Throwable $e) {
            AstraeaCore::log('critical', 'Vault admin operation faulted.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Critical system fault.');
        } finally {
            if ($master !== '') { $this->crypto->wipe($master); }
        }
    }

    public function verify(): void
    {
        $id = $this->backupIdFromPost();
        $this->authorize('manage_astraea_vault', 'astraea_vault_verify_' . $id);
        $master = '';
        try {
            $master = $this->keys->unlockWithServiceKey();
            $result = PhpErrorGuard::run(fn(): array => $this->verifier->verify($id, $master));
            $this->repository->updateStatus($id, 'verified', (int)$result['size'], (string)$result['sha256']);
            $this->redirect('ok', 'Backup integrity verified.');
        } catch (ValidationException $e) {
            $this->redirect('error', $e->getMessage());
        } catch (SecurityException $e) {
            AstraeaCore::log('security', 'Security boundary rejected an admin operation.', ['exception' => get_class($e)]);
            try { $this->repository->updateStatus($id, 'corrupt'); } catch (\Throwable) {}
            $this->redirect('error', 'Request rejected for security reasons.');
        } catch (StorageException $e) {
            AstraeaCore::log('error', 'Vault storage operation failed.', ['exception' => get_class($e)]);
            $this->redirect('error', 'A server error occurred.');
        } catch (\Throwable $e) {
            AstraeaCore::log('critical', 'Vault admin operation faulted.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Critical system fault.');
        } finally {
            if ($master !== '') { $this->crypto->wipe($master); }
        }
    }

    public function restore(): void
    {
        $id = $this->backupIdFromPost();
        $this->authorize('restore_astraea_vault', 'astraea_vault_restore_' . $id);
        $this->requireStepUp('vault:restore');
        $passphrase = isset($_POST['passphrase']) ? (string)wp_unslash($_POST['passphrase']) : '';
        $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : 'full';
        $serviceMaster = '';
        $userMaster = '';
        $safetyBackupId = '';
        try {
            $userId = get_current_user_id();
            $this->rateLimiter->assertAllowed($userId);

            // Authenticate the Vault passphrase before any expensive safety snapshot work.
            // This prevents an authenticated wp-admin account from turning wrong-passphrase
            // attempts into repeated full-site backup I/O.
            try {
                $userMaster = $this->keys->unlockWithPassphrase($passphrase);
                $this->rateLimiter->success($userId);
            } catch (SecurityException $e) {
                $this->rateLimiter->failure($userId);
                throw $e;
            }

            $serviceMaster = $this->keys->unlockWithServiceKey();
            $safety = PhpErrorGuard::run(fn(): array => $this->backups->create(
                'full',
                $serviceMaster,
                'pre_restore',
                ['transaction_id' => $id]
            ));
            $safetyBackupId = (string)($safety['id'] ?? '');
            if ($safetyBackupId === '') {
                throw new StorageException('Pre-restore safety backup identifier is unavailable.');
            }
            $this->crypto->wipe($serviceMaster);

            try {
                PhpErrorGuard::run(fn() => $this->restore->restore($id, $userMaster, $scope));
            } catch (\Throwable $restoreError) {
                AstraeaCore::log('critical', 'Manual restore failed; attempting safety rollback.', [
                    'backup_id' => $id,
                    'safety_backup_id' => $safetyBackupId,
                    'exception' => get_class($restoreError),
                ]);
                try {
                    $serviceMaster = $this->keys->unlockWithServiceKey();
                    PhpErrorGuard::run(fn() => $this->restore->restore($safetyBackupId, $serviceMaster, 'full'));
                    AstraeaCore::securityEvent('manual_restore_safety_rollback', [
                        'backup_id' => $id,
                        'safety_backup_id' => $safetyBackupId,
                        'user_id' => $userId,
                    ]);
                    $this->notifier->info(
                        'Restore rolled back safely',
                        'The requested restore failed. Astraea Vault restored the pre-restore safety snapshot.',
                        'warning'
                    );
                } catch (\Throwable $rollbackError) {
                    AstraeaCore::securityEvent('manual_restore_safety_rollback_failed', [
                        'backup_id' => $id,
                        'safety_backup_id' => $safetyBackupId,
                        'restore_exception' => get_class($restoreError),
                        'rollback_exception' => get_class($rollbackError),
                    ]);
                    throw new StorageException('Restore and automatic safety rollback both failed.', 0, $rollbackError);
                }
                throw $restoreError;
            }

            Scheduler::reschedule();
            AstraeaCore::securityEvent('manual_restore_completed', [
                'backup_id' => $id,
                'safety_backup_id' => $safetyBackupId,
                'user_id' => $userId,
            ]);
            $this->redirect('ok', 'Restore completed after a verified pre-restore safety snapshot.');
        } catch (ValidationException $e) {
            $this->redirect('error', $e->getMessage());
        } catch (SecurityException $e) {
            AstraeaCore::log('security', 'Security boundary rejected an admin operation.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Request rejected for security reasons.');
        } catch (StorageException $e) {
            AstraeaCore::log('error', 'Vault storage operation failed.', ['exception' => get_class($e)]);
            $this->redirect('error', 'A server error occurred.');
        } catch (\Throwable $e) {
            AstraeaCore::log('critical', 'Vault admin operation faulted.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Critical system fault.');
        } finally {
            if ($serviceMaster !== '') { $this->crypto->wipe($serviceMaster); }
            if ($userMaster !== '') { $this->crypto->wipe($userMaster); }
        }
    }

    public function delete(): void
    {
        $id = $this->backupIdFromPost();
        $this->authorize('manage_astraea_vault', 'astraea_vault_delete_' . $id);
        $this->requireStepUp('vault:delete');
        $passphrase = isset($_POST['passphrase']) ? (string)wp_unslash($_POST['passphrase']) : '';
        try {
            $this->verifyPassphrase($passphrase);
            $record = $this->repository->find($id);
            if ($record === null) { throw new ValidationException('Backup does not exist.'); }
            if (!empty($record['protected'])) { throw new ValidationException('Protected backup cannot be deleted.'); }
            $this->storage->deleteBackup($id);
            $this->repository->delete($id);
            AstraeaCore::securityEvent('backup_deleted', ['backup_id' => $id, 'user_id' => get_current_user_id()]);
            $this->redirect('ok', 'Backup deleted.');
        } catch (ValidationException $e) {
            $this->redirect('error', $e->getMessage());
        } catch (SecurityException $e) {
            AstraeaCore::log('security', 'Security boundary rejected an admin operation.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Request rejected for security reasons.');
        } catch (StorageException $e) {
            AstraeaCore::log('error', 'Vault storage operation failed.', ['exception' => get_class($e)]);
            $this->redirect('error', 'A server error occurred.');
        } catch (\Throwable $e) {
            AstraeaCore::log('critical', 'Vault admin operation faulted.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Critical system fault.');
        }
    }

    public function download(): void
    {
        $id = $this->backupIdFromPost();
        $this->authorize('download_astraea_vault', 'astraea_vault_download_' . $id);
        $this->requireStepUp('vault:download');
        $passphrase = isset($_POST['passphrase']) ? (string)wp_unslash($_POST['passphrase']) : '';
        try {
            $this->verifyPassphrase($passphrase);
            $path = $this->storage->resolveExistingBackup($id);
            $size = filesize($path);
            if ($size === false) { throw new StorageException('Unable to measure backup download.'); }
            AstraeaCore::securityEvent('backup_downloaded', ['backup_id' => $id, 'user_id' => get_current_user_id()]);
            while (ob_get_level() > 0) { ob_end_clean(); }
            nocache_headers();
            header('Cache-Control: no-store, private, max-age=0');
            header('Content-Type: application/octet-stream');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: no-referrer');
            header('Content-Disposition: attachment; filename="astraea-vault-' . rawurlencode($id) . '.avb"');
            header('Content-Length: ' . $size);
            $handle = @fopen($path, 'rb');
            if (!is_resource($handle)) { throw new StorageException('Unable to open backup download.'); }
            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 1048576);
                    if ($chunk === false) { throw new StorageException('Backup download stream failed.'); }
                    if ($chunk === '') { continue; }
                    echo $chunk;
                }
            } finally {
                fclose($handle);
            }
            exit;
        } catch (SecurityException $e) {
            AstraeaCore::log('security', 'Security boundary rejected a backup download.', ['exception' => get_class($e)]);
            wp_die(esc_html__('Request rejected for security reasons.', 'astraea-vault'), '', ['response' => 403]);
        } catch (StorageException $e) {
            AstraeaCore::log('error', 'Vault download storage operation failed.', ['exception' => get_class($e)]);
            wp_die(esc_html__('A server error occurred.', 'astraea-vault'), '', ['response' => 500]);
        } catch (\Throwable $e) {
            AstraeaCore::log('critical', 'Vault download faulted.', ['exception' => get_class($e)]);
            wp_die(esc_html__('Critical system fault.', 'astraea-vault'), '', ['response' => 500]);
        }
    }

    public function settings(): void
    {
        $this->authorize('manage_astraea_vault', 'astraea_vault_settings');
        $this->requireStepUp('vault:security-policy');
        try {
            $schedule = isset($_POST['schedule']) ? sanitize_key(wp_unslash($_POST['schedule'])) : 'daily';
            $allowed = ['off','hourly','twicedaily','daily','astraea_weekly'];
            if (!in_array($schedule, $allowed, true)) { throw new ValidationException('Invalid backup schedule.'); }
            $scheduledType = isset($_POST['scheduled_type']) ? sanitize_key(wp_unslash($_POST['scheduled_type'])) : 'full';
            if (!in_array($scheduledType, ['full','database'], true)) { throw new ValidationException('Invalid scheduled backup type.'); }
            $retention = isset($_POST['retention']) ? (int)wp_unslash($_POST['retention']) : 14;
            if ($retention < 1 || $retention > 365) { throw new ValidationException('Retention must be between 1 and 365.'); }
            update_option(Config::OPTION_SETTINGS, [
                'schedule' => $schedule,
                'scheduled_type' => $scheduledType,
                'retention' => $retention,
                'update_guard' => isset($_POST['update_guard']),
                'strict_update_guard' => isset($_POST['strict_update_guard']),
                'auto_rollback' => isset($_POST['auto_rollback']),
                'email_notifications' => isset($_POST['email_notifications']),
            ], false);
            Scheduler::reschedule();
            $this->redirect('ok', 'Vault automation policy updated.');
        } catch (ValidationException $e) {
            $this->redirect('error', $e->getMessage());
        } catch (SecurityException $e) {
            AstraeaCore::log('security', 'Security boundary rejected an admin operation.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Request rejected for security reasons.');
        } catch (StorageException $e) {
            AstraeaCore::log('error', 'Vault storage operation failed.', ['exception' => get_class($e)]);
            $this->redirect('error', 'A server error occurred.');
        } catch (\Throwable $e) {
            AstraeaCore::log('critical', 'Vault admin operation faulted.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Critical system fault.');
        }
    }

    public function change_passphrase(): void
    {
        $this->authorize('manage_astraea_vault', 'astraea_vault_change_passphrase');
        $this->requireStepUp('vault:passphrase-rotation');
        $old = isset($_POST['old_passphrase']) ? (string)wp_unslash($_POST['old_passphrase']) : '';
        $new = isset($_POST['new_passphrase']) ? (string)wp_unslash($_POST['new_passphrase']) : '';
        $confirm = isset($_POST['new_passphrase_confirm']) ? (string)wp_unslash($_POST['new_passphrase_confirm']) : '';
        try {
            $userId = get_current_user_id();
            $this->rateLimiter->assertAllowed($userId);
            if (!hash_equals($new, $confirm)) { throw new ValidationException('New passphrases do not match.'); }
            try {
                PhpErrorGuard::run(fn() => $this->keys->changePassphrase($old, $new));
                $this->rateLimiter->success($userId);
            } catch (SecurityException $e) {
                $this->rateLimiter->failure($userId);
                throw $e;
            }
            $this->redirect('ok', 'Vault passphrase rotated without re-encrypting backup payloads.');
        } catch (ValidationException $e) {
            $this->redirect('error', $e->getMessage());
        } catch (SecurityException $e) {
            AstraeaCore::log('security', 'Security boundary rejected an admin operation.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Request rejected for security reasons.');
        } catch (StorageException $e) {
            AstraeaCore::log('error', 'Vault storage operation failed.', ['exception' => get_class($e)]);
            $this->redirect('error', 'A server error occurred.');
        } catch (\Throwable $e) {
            AstraeaCore::log('critical', 'Vault admin operation faulted.', ['exception' => get_class($e)]);
            $this->redirect('error', 'Critical system fault.');
        }
    }

    private function renderRecoveryKey(string $recovery): never
    {
        nocache_headers();
        header('Cache-Control: no-store, private, max-age=0');
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        $css = esc_url(ASTRAEA_VAULT_URL . 'assets/admin.css?ver=' . rawurlencode(ASTRAEA_VAULT_VERSION));
        $js = esc_url(ASTRAEA_VAULT_URL . 'assets/admin.js?ver=' . rawurlencode(ASTRAEA_VAULT_VERSION));
        $return = esc_url(admin_url('admin.php?page=astraea-vault&av_status=ok&av_message=' . rawurlencode('Vault initialized.')));
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Astraea Vault Recovery Key</title><link rel="stylesheet" href="' . $css . '"></head><body class="av-standalone-body">';
        echo '<main class="av-wrap"><section class="av-panel av-recovery-key"><div class="av-panel-head"><div><div class="av-kicker">OFFLINE RECOVERY</div><h2>Save this recovery key now</h2></div></div>';
        echo '<p>This is the only plaintext display of the offline recovery key. Astraea Vault does not persist it.</p>';
        echo '<div class="av-secret-row"><code id="av-recovery-key">' . esc_html($recovery) . '</code><button type="button" class="button av-btn av-copy" data-copy-target="av-recovery-key">Copy</button></div>';
        echo '<p><a class="button av-btn av-primary" href="' . $return . '">I have saved the recovery key</a></p></section></main><script src="' . $js . '"></script></body></html>';
        exit;
    }

    private function requireStepUp(string $operation): void
    {
        if (!class_exists(\Astraea\Auth\StepUpAuthService::class)) {
            throw new SecurityException('Privileged authentication service unavailable.');
        }
        try {
            \Astraea\Auth\StepUpAuthService::guardSensitiveAction(get_current_user_id(), $operation);
        } catch (\Astraea\Exceptions\SecurityException $e) {
            throw new SecurityException('Privileged operation rejected by Astraea security policy.', 0, $e);
        }
    }

    private function authorize(string $capability, string $nonceAction): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'POST') {
            wp_die(esc_html__('Method not allowed.', 'astraea-vault'), '', ['response' => 405]);
        }
        if (!current_user_can($capability)) {
            wp_die(esc_html__('Forbidden.', 'astraea-vault'), '', ['response' => 403]);
        }
        check_admin_referer($nonceAction);
    }

    private function verifyPassphrase(string $passphrase): void
    {
        $userId = get_current_user_id();
        $this->rateLimiter->assertAllowed($userId);
        $master = '';
        try {
            try {
                $master = $this->keys->unlockWithPassphrase($passphrase);
                $this->rateLimiter->success($userId);
            } catch (SecurityException $e) {
                $this->rateLimiter->failure($userId);
                throw $e;
            }
        } finally {
            if ($master !== '') {
                $this->crypto->wipe($master);
            }
        }
    }

    private function backupIdFromPost(): string
    {
        $id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id) !== 1) {
            wp_die(esc_html__('Invalid backup identifier.', 'astraea-vault'), '', ['response' => 400]);
        }
        return $id;
    }

    private function redirect(string $status, string $message): never
    {
        wp_safe_redirect(add_query_arg(['page'=>'astraea-vault','av_status'=>$status,'av_message'=>$message], admin_url('admin.php')));
        exit;
    }
}
