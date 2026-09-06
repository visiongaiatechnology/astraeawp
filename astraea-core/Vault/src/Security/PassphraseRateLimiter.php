<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Security;

use Astraea\Vault\Exception\SecurityException;
use Astraea\Vault\Integration\AstraeaCore;

final class PassphraseRateLimiter
{
    private const META_KEY = 'astraea_vault_passphrase_guard';
    private const MAX_FAILURES = 5;
    private const WINDOW_SECONDS = 900;

    public function assertAllowed(int $userId): void
    {
        if ($userId <= 0) {
            throw new SecurityException('Passphrase token subject validation failed.');
        }

        $state = $this->readState($userId);
        if ($state === null) {
            return;
        }

        $now = time();
        if ($state['window_started_at'] + self::WINDOW_SECONDS <= $now) {
            delete_user_meta($userId, self::META_KEY);
            return;
        }

        if ($state['failures'] >= self::MAX_FAILURES) {
            AstraeaCore::securityEvent('passphrase_rate_limited', [
                'user_id' => $userId,
                'failures' => $state['failures'],
                'window_started_at' => $state['window_started_at'],
            ]);
            throw new SecurityException('Vault passphrase token rate limit exceeded.');
        }
    }

    public function failure(int $userId): void
    {
        if ($userId <= 0) {
            throw new SecurityException('Passphrase token subject validation failed.');
        }

        $now = time();
        $state = $this->readState($userId);
        if ($state === null || $state['window_started_at'] + self::WINDOW_SECONDS <= $now) {
            $state = ['failures' => 0, 'window_started_at' => $now];
        }

        $state['failures'] = min(PHP_INT_MAX, $state['failures'] + 1);
        update_user_meta($userId, self::META_KEY, $state);

        AstraeaCore::securityEvent('passphrase_failure', [
            'user_id' => $userId,
            'failures' => $state['failures'],
            'window_started_at' => $state['window_started_at'],
        ]);
    }

    public function success(int $userId): void
    {
        if ($userId > 0) {
            delete_user_meta($userId, self::META_KEY);
        }
    }

    /** @return array{failures:int,window_started_at:int}|null */
    private function readState(int $userId): ?array
    {
        $raw = get_user_meta($userId, self::META_KEY, true);
        if (!is_array($raw)) {
            return null;
        }

        $failures = isset($raw['failures']) ? (int)$raw['failures'] : 0;
        $started = isset($raw['window_started_at']) ? (int)$raw['window_started_at'] : 0;
        if ($failures < 0 || $started <= 0) {
            delete_user_meta($userId, self::META_KEY);
            return null;
        }

        return ['failures' => $failures, 'window_started_at' => $started];
    }
}
