<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Auth;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;
use Astraea\Security\SecurityEventManager;
use WP_Session_Tokens;

/**
 * AstraeaOS Session Manager.
 *
 * Provides cryptographic, device-aware session management:
 * - Device and OS classification from User-Agent without external dependencies
 * - Privacy-preserving IP masking
 * - Session revocation via WP_Session_Tokens
 * - Nonce-hardened AJAX handlers for remote and bulk session termination
 *
 * @package Astraea\Auth
 */
final class SessionManager {

    public const NONCE_ACTION = 'astraea_session_revoke_nonce';

    /**
     * Boot session manager hooks and AJAX endpoints.
     */
    public static function init(): void {
        add_action('wp_ajax_astraea_revoke_session', [self::class, 'handleRevokeAjax']);
        add_action('wp_ajax_astraea_revoke_other_sessions', [self::class, 'handleRevokeOthersAjax']);
    }

    /**
     * Retrieve all active sessions for a specific user.
     *
     * @param int $userId
     * @return array<int, array<string, mixed>>
     */
    public static function getUserSessions(int $userId): array {
        if ($userId <= 0 || !class_exists(WP_Session_Tokens::class)) {
            return [];
        }

        $rawSessions = get_user_meta($userId, 'session_tokens', true);
        if (!is_array($rawSessions) || empty($rawSessions)) {
            return [];
        }

        $currentToken = function_exists('wp_get_session_token') ? wp_get_session_token() : '';
        $currentVerifier = $currentToken !== '' ? hash('sha256', $currentToken) : '';

        $sessions = [];
        $now = time();
        foreach ($rawSessions as $verifier => $session) {
            if (!is_array($session)) {
                continue;
            }

            $expiration = isset($session['expiration']) && is_numeric($session['expiration']) ? (int) $session['expiration'] : 0;
            if ($expiration > 0 && $expiration < $now) {
                continue;
            }

            $ua = isset($session['ua']) && is_string($session['ua']) ? $session['ua'] : '';
            $ip = isset($session['ip']) && is_string($session['ip']) ? $session['ip'] : '';
            $login = isset($session['login']) && is_numeric($session['login']) ? (int) $session['login'] : 0;
            $expiration = isset($session['expiration']) && is_numeric($session['expiration']) ? (int) $session['expiration'] : 0;

            $isCurrent = ($currentVerifier !== '' && hash_equals($currentVerifier, (string) $verifier));

            $sessions[] = [
                'verifier'    => (string) $verifier,
                'device'      => self::parseUserAgent($ua),
                'raw_ua'      => $ua,
                'ip_masked'   => self::maskIp($ip),
                'ip_raw'      => $ip,
                'login_time'  => $login,
                'expiration'  => $expiration,
                'is_current'  => $isCurrent,
            ];
        }

        // Sort current session to top, then newest login first
        usort($sessions, static function (array $a, array $b): int {
            if ($a['is_current'] !== $b['is_current']) {
                return $a['is_current'] ? -1 : 1;
            }
            return $b['login_time'] <=> $a['login_time'];
        });

        return $sessions;
    }

    /**
     * Revoke a specific session for a user.
     *
     * @param int $userId
     * @param string $verifier
     * @return bool
     */
    public static function revokeSession(int $userId, string $verifier): bool {
        if ($userId <= 0 || strlen($verifier) !== 64 || !ctype_xdigit($verifier) || !class_exists(WP_Session_Tokens::class)) {
            return false;
        }

        // Sessions in WordPress are stored in user meta 'session_tokens' keyed by the SHA-256 verifier.
        // Calling WP_Session_Tokens::destroy($token) expects the unhashed token and would re-hash it,
        // causing double-hashing when passing a verifier. We remove the verifier directly from the store.
        $sessions = get_user_meta($userId, 'session_tokens', true);
        if (!is_array($sessions) || !isset($sessions[$verifier])) {
            return false;
        }

        $originalSessions = $sessions;
        unset($sessions[$verifier]);

        if (!empty($sessions)) {
            return update_user_meta($userId, 'session_tokens', $sessions, $originalSessions) === true;
        }
        return delete_user_meta($userId, 'session_tokens', $originalSessions) === true;
    }

    /**
     * Revoke all sessions except the current session.
     *
     * @param int $userId
     * @return bool
     */
    public static function revokeOtherSessions(int $userId): bool {
        if ($userId <= 0 || !class_exists(WP_Session_Tokens::class)) {
            return false;
        }

        $currentToken = function_exists('wp_get_session_token') ? wp_get_session_token() : '';
        if ($currentToken === '') {
            return false;
        }

        $currentVerifier = hash('sha256', $currentToken);
        $sessions = get_user_meta($userId, 'session_tokens', true);
        if (!is_array($sessions) || !isset($sessions[$currentVerifier])) {
            return false;
        }

        // WP_Session_Tokens::destroy_others expects the raw, unhashed token to preserve the active session.
        $manager = WP_Session_Tokens::get_instance($userId);
        $manager->destroy_others($currentToken);
        $remaining = get_user_meta($userId, 'session_tokens', true);
        return is_array($remaining)
            && count($remaining) === 1
            && isset($remaining[$currentVerifier]);
    }

    /**
     * Parse User-Agent into clean human-readable device and browser components.
     *
     * @param string $ua
     * @return array{os: string, browser: string, label: string}
     */
    public static function parseUserAgent(string $ua): array {
        if ($ua === '') {
            return [
                'os'      => 'Unknown OS',
                'browser' => 'Unknown Browser',
                'label'   => 'Unknown Device / API Client',
            ];
        }

        $os = 'Unknown OS';
        if (preg_match('/Windows NT 10\.0/i', $ua)) {
            $os = 'Windows 10/11';
        } elseif (preg_match('/Windows NT 6\.[123]/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        $browser = 'Browser';
        if (preg_match('/Edg(?:e)?\/([0-9]+)/i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\/([0-9]+)/i', $ua) && !preg_match('/Edg/i', $ua)) {
            $browser = preg_match('/Mobile/i', $ua) ? 'Chrome Mobile' : 'Chrome';
        } elseif (preg_match('/Safari\/([0-9]+)/i', $ua) && !preg_match('/Chrome/i', $ua)) {
            $browser = preg_match('/Mobile/i', $ua) ? 'Mobile Safari' : 'Safari';
        } elseif (preg_match('/Firefox\/([0-9]+)/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/curl|wget|python|postman/i', $ua)) {
            $browser = 'CLI Client';
        }

        return [
            'os'      => $os,
            'browser' => $browser,
            'label'   => "{$os} · {$browser}",
        ];
    }

    /**
     * Mask IP address for privacy preservation (GDPR / ISO 27001).
     *
     * @param string $ip
     * @return string
     */
    public static function maskIp(string $ip): string {
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return '127.0.0.1 (Localhost)';
        }

        // IPv4: mask last octet
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.***';
            }
        }

        // IPv6: mask host segment
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            if (count($parts) >= 2) {
                return $parts[0] . ':' . $parts[1] . ':****';
            }
        }

        return '0.0.0.0';
    }

    /**
     * AJAX handler for revoking a single session.
     */
    public static function handleRevokeAjax(): void {
        try {
            self::verifyAjaxRequest();

            $verifier = isset($_POST['verifier']) && is_string($_POST['verifier']) ? sanitize_text_field($_POST['verifier']) : '';
            if ($verifier === '' || strlen($verifier) !== 64) {
                throw new ValidationException('Invalid session identifier.');
            }

            $currentUserId = get_current_user_id();
            StepUpAuthService::guardSensitiveAction($currentUserId, 'session:revoke');
            $success = self::revokeSession($currentUserId, $verifier);

            if (!$success) {
                throw new SecurityException('Failed to terminate the requested session.');
            }

            SecurityEventManager::recordEvent(
                SecurityEventManager::SEVERITY_INFO,
                'Authentication',
                'session_revoked',
                'An authenticated session was revoked by its account owner.',
                ['user_id' => $currentUserId, 'session' => substr($verifier, 0, 12)]
            );
            wp_send_json_success([
                'message' => 'Session terminated successfully.',
                'verifier' => $verifier,
            ]);
        } catch (ValidationException $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        } catch (SecurityException $e) {
            error_log('[SEC] Session revocation rejected: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Security policy rejected the termination request.'], 403);
        } catch (\Throwable $e) {
            error_log('[FATAL] Session revocation error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'System error during session revocation.'], 500);
        }
    }

    /**
     * AJAX handler for revoking all other sessions.
     */
    public static function handleRevokeOthersAjax(): void {
        try {
            self::verifyAjaxRequest();

            $currentUserId = get_current_user_id();
            StepUpAuthService::guardSensitiveAction($currentUserId, 'session:revoke-others');
            $success = self::revokeOtherSessions($currentUserId);

            if (!$success) {
                throw new SecurityException('Could not revoke auxiliary sessions.');
            }

            SecurityEventManager::recordEvent(
                SecurityEventManager::SEVERITY_INFO,
                'Authentication',
                'other_sessions_revoked',
                'All sessions except the current authenticated session were revoked.',
                ['user_id' => $currentUserId]
            );
            wp_send_json_success([
                'message' => 'All other sessions terminated successfully.',
            ]);
        } catch (ValidationException $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        } catch (SecurityException $e) {
            error_log('[SEC] Bulk session revocation rejected: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Security policy rejected the bulk revocation.'], 403);
        } catch (\Throwable $e) {
            error_log('[FATAL] Bulk session revocation error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'System error during bulk session revocation.'], 500);
        }
    }

    /**
     * Enforce authentication and nonce validity on AJAX endpoints.
     *
     * @throws SecurityException
     * @throws ValidationException
     */
    private static function verifyAjaxRequest(): void {
        if (!is_user_logged_in()) {
            throw new SecurityException('Unauthenticated session request.');
        }

        $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? $_POST['nonce'] ?? '';
        if (!is_string($nonce) || $nonce === '') {
            throw new SecurityException('Missing security nonce.');
        }

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            throw new SecurityException('Invalid security token.');
        }

        if (!current_user_can('read')) {
            throw new SecurityException('Insufficient user capabilities.');
        }
    }
}
