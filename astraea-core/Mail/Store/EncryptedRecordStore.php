<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Store;

use Astraea\Crypto\CryptoService;
use Astraea\Crypto\KeyContext;
use Astraea\Exceptions\StorageException;
use JsonException;

final class EncryptedRecordStore
{
    /** @return array<int,array<string,mixed>> */
    public static function loadList(string $option, string $aad): array
    {
        $raw = get_option($option, '');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $json = CryptoService::decrypt($raw, KeyContext::MAIL_TRANSPORT, $aad);
            $data = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
            return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
        } catch (\Throwable $e) {
            error_log('[ASTRAEA][MAIL] Encrypted mail record store could not be decoded: ' . get_class($e));
            return [];
        }
    }

    /** @param array<int,array<string,mixed>> $records */
    public static function saveList(string $option, string $aad, array $records): void
    {
        try {
            $json = json_encode(array_values($records), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $ciphertext = CryptoService::encrypt($json, KeyContext::MAIL_TRANSPORT, $aad);
            if (!update_option($option, $ciphertext, false)) {
                $existing = get_option($option, null);
                if (!is_string($existing) || !hash_equals($existing, $ciphertext)) {
                    throw new StorageException('Encrypted mail record store could not be persisted.');
                }
            }
        } catch (JsonException $e) {
            throw new StorageException('Mail record serialization failed.', 0, $e);
        }
    }

    /** @return array<string,mixed> */
    public static function loadObject(string $option, string $aad): array
    {
        $list = self::loadList($option, $aad);
        return $list[0] ?? [];
    }

    /** @param array<string,mixed> $record */
    public static function saveObject(string $option, string $aad, array $record): void
    {
        self::saveList($option, $aad, [$record]);
    }
}
