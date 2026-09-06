<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Store;

use Astraea\Crypto\CryptoService;
use Astraea\Crypto\KeyContext;
use Astraea\Exceptions\StorageException;
use Astraea\Mail\Settings;
use Astraea\Mail\SmtpConfig;
use JsonException;

final class EncryptedConfigStore
{
    private static bool $loadFailure = false;
    public static function load(): SmtpConfig
    {
        self::$loadFailure = false;
        $raw = get_option(Settings::CONFIG_OPTION, '');
        if (!is_string($raw) || $raw === '') {
            return SmtpConfig::defaults();
        }
        try {
            $json = CryptoService::decrypt($raw, KeyContext::MAIL_TRANSPORT, Settings::CONFIG_AAD);
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            return is_array($data) ? SmtpConfig::fromArray($data) : SmtpConfig::defaults();
        } catch (\Throwable $e) {
            self::$loadFailure = true;
            error_log('[ASTRAEA][MAIL] Encrypted SMTP configuration could not be decoded: ' . get_class($e));
            return SmtpConfig::defaults();
        }
    }


    public static function hasLoadFailure(): bool
    {
        return self::$loadFailure;
    }

    public static function save(SmtpConfig $config): void
    {
        try {
            $json = json_encode($config->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $ciphertext = CryptoService::encrypt($json, KeyContext::MAIL_TRANSPORT, Settings::CONFIG_AAD);
            if (!update_option(Settings::CONFIG_OPTION, $ciphertext, false)) {
                $existing = get_option(Settings::CONFIG_OPTION, null);
                if (!is_string($existing) || !hash_equals($existing, $ciphertext)) {
                    throw new StorageException('Encrypted SMTP configuration could not be persisted.');
                }
            }
        } catch (JsonException $e) {
            throw new StorageException('SMTP configuration serialization failed.', 0, $e);
        }
    }

    public static function delete(): void
    {
        delete_option(Settings::CONFIG_OPTION);
    }
}
