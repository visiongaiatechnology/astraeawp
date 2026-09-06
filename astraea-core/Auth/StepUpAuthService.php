<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Auth;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;
use Astraea\Security\SecurityEventManager;
use WP_User;

/**
 * Session-bound privileged re-authentication service.
 *
 * Elevation is bound to the exact WordPress session token verifier, never to
 * the user account globally. A second stolen session therefore cannot inherit
 * elevation performed in another browser/device.
 */
final class StepUpAuthService {

    public const GRACE_PERIOD_SECONDS = 900;
    public const SESSION_META_KEY = 'astraea_step_up_sessions';
    public const NONCE_ACTION = 'astraea_step_up_challenge';
    public const ADMIN_POST_ACTION = 'astraea_step_up';
    private const MAX_SESSION_RECORDS = 20;
    private const MAX_FAILURES = 5;
    private const FAILURE_WINDOW = 900;

    public static function init(): void {
        add_action('admin_post_' . self::ADMIN_POST_ACTION, [self::class, 'handleChallenge']);
        add_action('wp_logout', [self::class, 'handleLogout'], 10, 1);
    }

    public static function isStepUpActive(int $userId): bool {
        if ($userId <= 0 || !function_exists('get_user_meta')) {
            return false;
        }

        $fingerprint = self::currentSessionFingerprint();
        if ($fingerprint === '') {
            return false;
        }

        $records = self::records($userId);
        $now = time();
        foreach ($records as $storedFingerprint => $timestamp) {
            if (!is_string($storedFingerprint) || !is_int($timestamp)) {
                continue;
            }
            if (hash_equals($storedFingerprint, $fingerprint)) {
                return $timestamp > 0 && ($now - $timestamp) <= self::GRACE_PERIOD_SECONDS;
            }
        }

        return false;
    }

    public static function isCurrentSessionVerified(): bool {
        $userId = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        return $userId > 0 && self::isStepUpActive($userId);
    }

    public static function getVerificationUrl(string $redirectTo = ''): string {
        $base = function_exists('admin_url')
            ? admin_url('admin.php?page=astraea-security&tab=sessions')
            : '/wp-admin/admin.php?page=astraea-security&tab=sessions';
        return $redirectTo !== '' ? add_query_arg('redirect_to', urlencode($redirectTo), $base) : $base;
    }

    public static function recordStepUp(int $userId): void {
        if ($userId <= 0 || !function_exists('update_user_meta')) {
            return;
        }

        $fingerprint = self::currentSessionFingerprint();
        if ($fingerprint === '') {
            throw new SecurityException('Session token unavailable for privileged elevation.');
        }

        $records = self::prune(self::records($userId));
        $records[$fingerprint] = time();
        arsort($records, SORT_NUMERIC);
        $records = array_slice($records, 0, self::MAX_SESSION_RECORDS, true);
        update_user_meta($userId, self::SESSION_META_KEY, $records);

        SecurityEventManager::recordEvent(
            SecurityEventManager::SEVERITY_INFO,
            'Authentication',
            'step_up_granted',
            'Privileged re-authentication granted for the current session.',
            ['user_id' => $userId, 'session' => substr($fingerprint, 0, 12)]
        );
    }

    public static function clearStepUp(int $userId, bool $allSessions = false): void {
        if ($userId <= 0 || !function_exists('delete_user_meta')) {
            return;
        }

        if ($allSessions) {
            delete_user_meta($userId, self::SESSION_META_KEY);
            return;
        }

        $fingerprint = self::currentSessionFingerprint();
        if ($fingerprint === '' || !function_exists('update_user_meta')) {
            return;
        }

        $records = self::records($userId);
        foreach (array_keys($records) as $storedFingerprint) {
            if (is_string($storedFingerprint) && hash_equals($storedFingerprint, $fingerprint)) {
                unset($records[$storedFingerprint]);
            }
        }

        if ($records === []) {
            delete_user_meta($userId, self::SESSION_META_KEY);
        } else {
            update_user_meta($userId, self::SESSION_META_KEY, self::prune($records));
        }
    }

    public static function verifyStepUpChallenge(int $userId, string $password): bool {
        if ($userId <= 0) {
            return false;
        }
        if ($password === '') {
            throw new ValidationException('Password cannot be empty.');
        }

        $user = get_userdata($userId);
        if (!($user instanceof WP_User) || empty($user->user_pass)) {
            return false;
        }

        $passwordService = new PasswordService();
        $isValid = $passwordService->verify($password, (string)$user->user_pass);
        if ($isValid) {
            self::recordStepUp($userId);
        }
        return $isValid;
    }

    public static function guardSensitiveAction(int $userId, string $actionName): void {
        if (self::isStepUpActive($userId)) {
            return;
        }

        SecurityEventManager::recordOnce(
            SecurityEventManager::SEVERITY_WARNING,
            'Authentication',
            'step_up_required',
            'A privileged operation was blocked because the current session was not re-authenticated.',
            ['user_id' => $userId, 'operation' => self::safeActionName($actionName)],
            30
        );

        throw new SecurityException('Privileged operation requires current-session step-up authentication.');
    }

    public static function handleChallenge(): never {
        $fallback = admin_url('admin.php?page=astraea-security&tab=sessions');
        try {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                throw new SecurityException('Step-up challenge rejected due to invalid HTTP method.');
            }
            if (!is_user_logged_in()) {
                throw new SecurityException('Unauthenticated step-up challenge rejected.');
            }
            if (!current_user_can('read')) {
                throw new SecurityException('Step-up challenge rejected due to insufficient capability.');
            }
            check_admin_referer(self::NONCE_ACTION);

            $userId = get_current_user_id();
            self::assertRateLimit($userId);
            $password = isset($_POST['astraea_password']) ? (string)wp_unslash($_POST['astraea_password']) : '';
            $redirect = isset($_POST['redirect_to']) && is_string($_POST['redirect_to'])
                ? wp_validate_redirect((string)wp_unslash($_POST['redirect_to']), $fallback)
                : $fallback;

            if (!self::verifyStepUpChallenge($userId, $password)) {
                self::recordFailure($userId);
                SecurityEventManager::recordEvent(
                    SecurityEventManager::SEVERITY_WARNING,
                    'Authentication',
                    'step_up_failed',
                    'Privileged re-authentication failed for the current session.',
                    ['user_id' => $userId]
                );
                wp_safe_redirect(add_query_arg('astraea_stepup', 'failed', $redirect));
                exit;
            }

            self::clearFailures($userId);
            wp_safe_redirect(add_query_arg('astraea_stepup', 'granted', $redirect));
            exit;
        } catch (ValidationException $e) {
            wp_safe_redirect(add_query_arg('astraea_stepup', 'invalid', $fallback));
            exit;
        } catch (SecurityException $e) {
            SecurityEventManager::recordOnce(
                SecurityEventManager::SEVERITY_WARNING,
                'Authentication',
                'step_up_rejected',
                'Privileged re-authentication request was rejected by security policy.',
                [],
                30
            );
            wp_die(esc_html__('Request rejected for security reasons.', 'astraeaos'), '', ['response' => 403]);
        } catch (\Throwable $e) {
            error_log('[ASTRAEA][STEP-UP] Critical system fault: ' . get_class($e));
            wp_die(esc_html__('Critical system fault.', 'astraeaos'), '', ['response' => 500]);
        }
    }

    public static function handleLogout(int $userId): void {
        self::clearStepUp($userId);
    }

    public static function currentSessionFingerprint(): string {
        if (!function_exists('wp_get_session_token')) {
            return '';
        }
        $token = wp_get_session_token();
        if (!is_string($token) || $token === '') {
            return '';
        }
        return hash('sha256', $token);
    }

    /** @return array<string,int> */
    private static function records(int $userId): array {
        $raw = get_user_meta($userId, self::SESSION_META_KEY, true);
        if (!is_array($raw)) {
            return [];
        }
        $result = [];
        foreach ($raw as $fingerprint => $timestamp) {
            if (!is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1 || !is_numeric($timestamp)) {
                continue;
            }
            $result[$fingerprint] = (int)$timestamp;
        }
        return $result;
    }

    /** @param array<string,int> $records @return array<string,int> */
    private static function prune(array $records): array {
        $cutoff = time() - self::GRACE_PERIOD_SECONDS;
        foreach ($records as $fingerprint => $timestamp) {
            if ($timestamp < $cutoff) {
                unset($records[$fingerprint]);
            }
        }
        return $records;
    }

    private static function failureKey(int $userId): string {
        $fingerprint = self::currentSessionFingerprint();
        return 'astraea_stepup_fail_' . substr(hash('sha256', $userId . '|' . $fingerprint), 0, 32);
    }

    private static function assertRateLimit(int $userId): void {
        $attempts = (int)get_transient(self::failureKey($userId));
        if ($attempts >= self::MAX_FAILURES) {
            throw new SecurityException('Step-up authentication rate limit exceeded.');
        }
    }

    private static function recordFailure(int $userId): void {
        $key = self::failureKey($userId);
        $attempts = (int)get_transient($key);
        set_transient($key, min(self::MAX_FAILURES, $attempts + 1), self::FAILURE_WINDOW);
    }

    private static function clearFailures(int $userId): void {
        delete_transient(self::failureKey($userId));
    }

    private static function safeActionName(string $actionName): string {
        $safe = preg_replace('/[^a-z0-9:_-]/i', '_', $actionName) ?? 'privileged_action';
        return substr($safe, 0, 96);
    }
}
