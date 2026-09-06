<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Auth;

use Astraea\Crypto\CryptoService;
use Astraea\Crypto\KeyContext;
use Astraea\Security\SecurityEventManager;

/**
 * Astraea Identity Center & Authentication Management.
 *
 * Coordinates active session inspection, login history telemetry, password policy,
 * recovery codes, and truthful WebAuthn status reporting.
 *
 * @package Astraea\Auth
 */
final class IdentityCenter {

    public const OPTION_LOGIN_HISTORY = 'astraea_login_history';
    public const MAX_LOGIN_HISTORY = 100;

    public static function init(): void {
        add_action('wp_login', [self::class, 'recordLoginSuccess'], 10, 2);
        add_action('wp_login_failed', [self::class, 'recordLoginFailed']);
        add_action('admin_menu', [self::class, 'registerAdminMenu']);
    }

    public static function registerAdminMenu(): void {
        if (!is_admin()) {
            return;
        }

        add_submenu_page(
            'users.php',
            'Astraea Identity Center',
            'Identity Center',
            'read',
            'astraea-identity',
            [self::class, 'renderScreen']
        );
    }

    public static function recordLoginSuccess(string $userLogin, \WP_User $user): void {
        self::appendLoginRecord($userLogin, true);
    }

    public static function recordLoginFailed(string $userLogin): void {
        self::appendLoginRecord($userLogin, false);
    }

    private static function appendLoginRecord(string $username, bool $success): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $record = [
            'username'   => sanitize_user($username),
            'success'    => $success,
            'ip_masked'  => SessionManager::maskIp($ip),
            'device'     => SessionManager::parseUserAgent($ua),
            'timestamp'  => time(),
        ];

        $history = get_option(self::OPTION_LOGIN_HISTORY, []);
        if (!is_array($history)) {
            $history = [];
        }

        if (count($history) >= self::MAX_LOGIN_HISTORY) {
            array_shift($history);
        }

        $history[] = $record;
        update_option(self::OPTION_LOGIN_HISTORY, $history, false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getLoginHistory(): array {
        $raw = get_option(self::OPTION_LOGIN_HISTORY, []);
        return is_array($raw) ? array_reverse($raw) : [];
    }

    /**
     * Get active login sessions for the current authenticated user context.
     *
     * @return list<array<string, mixed>>
     */
    public static function getActiveSessions(): array {
        $currentUserId = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        return SessionManager::getUserSessions($currentUserId);
    }

    public static function renderScreen(): void {
        $currentUserId = get_current_user_id();
        $sessions = SessionManager::getUserSessions($currentUserId);
        $history = self::getLoginHistory();
        $stepUpVerified = StepUpAuthService::isCurrentSessionVerified();
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 1000px; margin: 24px auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea Identity Center</h1>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">Cryptographic session telemetry, privilege isolation, and authentication security posture.</p>
            </div>

            <!-- Telemetry HUD Grid -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 18px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Active Sessions</div>
                    <div style="font-size: 24px; font-weight: 700; color: #38bdf8; margin: 6px 0 2px; font-family: monospace;"><?php echo count($sessions); ?></div>
                    <div style="font-size: 11px; color: #64748b;">Device-bound tokens</div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 18px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Password Hashing</div>
                    <div style="font-size: 20px; font-weight: 700; color: #4ade80; margin: 6px 0 2px;">Argon2id</div>
                    <div style="font-size: 11px; color: #64748b;">Active memory-hard KDF</div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 18px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Step-Up Status</div>
                    <div style="font-size: 20px; font-weight: 700; color: <?php echo $stepUpVerified ? '#4ade80' : '#facc15'; ?>; margin: 6px 0 2px;">
                        <?php echo $stepUpVerified ? 'VERIFIED' : 'UNVERIFIED'; ?>
                    </div>
                    <div style="font-size: 11px; color: #64748b;">Session-bound elevation</div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 18px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">WebAuthn / Passkeys</div>
                    <div style="font-size: 16px; font-weight: 700; color: #94a3b8; margin: 10px 0 4px;">NOT IMPLEMENTED</div>
                    <div style="font-size: 11px; color: #64748b;">Truthful reporting (No stubs)</div>
                </div>
            </div>

            <!-- Active Sessions Table -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                <h3 style="color: #f8fafc; margin: 0 0 14px; font-size: 15px;">Your Active Login Sessions</h3>
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; color: #cbd5e1;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); color: #94a3b8; font-size: 11px; text-transform: uppercase;">
                            <th style="padding: 10px 12px;">Device / Browser</th>
                            <th style="padding: 10px 12px;">IP Address</th>
                            <th style="padding: 10px 12px;">Login Time</th>
                            <th style="padding: 10px 12px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $s): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                <td style="padding: 12px; font-weight: 600; color: #f1f5f9;">
                                    <?php echo esc_html($s['device']['browser'] . ' on ' . $s['device']['os']); ?>
                                </td>
                                <td style="padding: 12px; font-family: monospace; font-size: 12px; color: #94a3b8;">
                                    <?php echo esc_html($s['ip_masked']); ?>
                                </td>
                                <td style="padding: 12px; font-size: 12px; color: #cbd5e1;">
                                    <?php echo esc_html(date_i18n('Y-m-d H:i', (int)$s['login_time'])); ?>
                                </td>
                                <td style="padding: 12px;">
                                    <?php if ($s['is_current']): ?>
                                        <span style="background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 1px solid rgba(74, 222, 128, 0.3); padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">Current Session</span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">Active</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Login History -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                <h3 style="color: #f8fafc; margin: 0 0 14px; font-size: 15px;">Recent Authentication Attempts</h3>
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; color: #cbd5e1;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); color: #94a3b8; font-size: 11px; text-transform: uppercase;">
                            <th style="padding: 10px 12px;">Username</th>
                            <th style="padding: 10px 12px;">Outcome</th>
                            <th style="padding: 10px 12px;">IP Address</th>
                            <th style="padding: 10px 12px;">Device</th>
                            <th style="padding: 10px 12px;">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                            <tr><td colspan="5" style="padding: 16px; text-align: center; color: #64748b;">No recent login events recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach (array_slice($history, 0, 15) as $h): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                    <td style="padding: 10px 12px; font-family: monospace; color: #f1f5f9;"><?php echo esc_html((string)$h['username']); ?></td>
                                    <td style="padding: 10px 12px;">
                                        <span style="font-weight: 600; font-size: 11px; padding: 2px 6px; border-radius: 4px; <?php echo $h['success'] ? 'background: rgba(34,197,94,0.15); color: #4ade80;' : 'background: rgba(239,68,68,0.15); color: #f87171;'; ?>">
                                            <?php echo $h['success'] ? 'SUCCESS' : 'FAILED'; ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px 12px; font-family: monospace; font-size: 12px; color: #94a3b8;"><?php echo esc_html((string)$h['ip_masked']); ?></td>
                                    <td style="padding: 10px 12px; color: #94a3b8; font-size: 12px;"><?php echo esc_html($h['device']['browser'] . ' on ' . $h['device']['os']); ?></td>
                                    <td style="padding: 10px 12px; color: #cbd5e1; font-size: 12px;"><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$h['timestamp'])); ?></td>
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
