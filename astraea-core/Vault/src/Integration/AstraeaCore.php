<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Integration;

use Astraea\Vault\Crypto\CryptoService;
use Astraea\Vault\Exception\SecurityException;

/**
 * Runtime bridge into AstraeaOS WP Core services.
 *
 * Keeps Vault installable as a standalone plugin while automatically promoting
 * security-sensitive operations to the native Astraea core when available.
 */
final class AstraeaCore
{
    public static function available(): bool
    {
        return defined('ASTRAEA_VERSION')
            && class_exists('\\Astraea\\Crypto\\MasterKeyManager')
            && class_exists('\\Astraea\\Security\\Logger');
    }

    /**
     * Derive the unattended Vault service key from the AstraeaOS master key.
     * The core master key itself is never persisted by Vault.
     */
    public static function deriveVaultServiceKey(CryptoService $crypto): ?string
    {
        if (!class_exists('\\Astraea\\Crypto\\MasterKeyManager')) {
            return null;
        }

        try {
            /** @var class-string $manager */
            $manager = '\\Astraea\\Crypto\\MasterKeyManager';
            $master = $manager::getMasterKey();
            if (!is_string($master) || strlen($master) !== CryptoService::KEY_BYTES) {
                throw new SecurityException('Astraea master key boundary validation failed.');
            }
            try {
                return $crypto->hkdf($master, 'astraea:vault:service:core-master:v1');
            } finally {
                // This only clears Vault's local copy; Astraea Core controls its own in-memory cache.
                $crypto->wipe($master);
            }
        } catch (\Throwable $e) {
            self::log('security', 'Vault could not derive an unattended key from Astraea Core.', [
                'exception' => get_class($e),
            ]);
            return null;
        }
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        if (class_exists('\\Astraea\\Security\\Logger')) {
            /** @var class-string $logger */
            $logger = '\\Astraea\\Security\\Logger';
            $method = match (strtolower($level)) {
                'debug' => 'debug',
                'info' => 'info',
                'notice' => 'notice',
                'warning', 'warn' => 'warning',
                'error' => 'error',
                'critical', 'fatal' => 'critical',
                default => 'security',
            };
            try {
                $logger::$method('[Vault] ' . $message, $context);
                return;
            } catch (\Throwable) {
                // Fall through to a local opaque log sink.
            }
        }

        try {
            $safe = self::redact($context);
            $encoded = json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            error_log('[ASTRAEA_VAULT][' . strtoupper($level) . '] ' . $message . ' ' . $encoded);
        } catch (\Throwable) {
            error_log('[ASTRAEA_VAULT][' . strtoupper($level) . '] ' . $message);
        }
    }

    public static function securityEvent(string $event, array $context = []): void
    {
        $safe = self::redact($context);
        self::log('security', $event, $safe);

        if (class_exists('\\VisionGaia\\GeDefense\\Core\\EventBus')) {
            try {
                /** @var class-string $bus */
                $bus = '\\VisionGaia\\GeDefense\\Core\\EventBus';
                $severity = str_contains($event, 'failed') || str_contains($event, 'rollback') ? 7 : 4;
                $bus::emit('ASTRAEA_VAULT', strtoupper($event), 'Astraea Vault security event.', $safe, $severity);
            } catch (\Throwable) {
                // GeDefense integration must never break Vault recovery.
            }
        }

        if (function_exists('do_action')) {
            do_action('astraea_vault_security_event', $event, $safe);
        }
    }

    private static function redact(array $context): array
    {
        $sensitive = ['password', 'passphrase', 'secret', 'token', 'key', 'cookie', 'authorization', 'session'];
        $clean = [];
        foreach ($context as $key => $value) {
            $name = is_string($key) ? strtolower($key) : '';
            $blocked = false;
            foreach ($sensitive as $needle) {
                if ($name !== '' && str_contains($name, $needle)) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                $clean[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $clean[$key] = self::redact($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = get_debug_type($value);
            }
        }
        return $clean;
    }
}
