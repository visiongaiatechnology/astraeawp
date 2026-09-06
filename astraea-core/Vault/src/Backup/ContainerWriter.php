<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Backup;

use Astraea\Vault\Crypto\CryptoService;
use Astraea\Vault\Exception\StorageException;
use Astraea\Vault\Exception\ValidationException;

final class ContainerWriter
{
    public const MAGIC = "AVB1\r\n\x1A\n";
    public const VERSION = 1;
    public const TYPE_HEADER_AUTH = 10;
    public const TYPE_FILE_START = 20;
    public const TYPE_FILE_CHUNK = 21;
    public const TYPE_FILE_END = 22;
    public const TYPE_DB_TABLE = 30;
    public const TYPE_DB_ROWS = 31;
    public const TYPE_DB_ROW_START = 32;
    public const TYPE_DB_ROW_CELL = 33;
    public const TYPE_DB_ROW_END = 34;
    public const TYPE_END = 255;
    private const MAX_COUNTER = 4294967294;

    private string $dataKey;
    private string $noncePrefix;
    private int $counter = 0;
    private bool $ended = false;
    private string $headerJson;

    public function __construct(
        private $handle,
        private readonly CryptoService $crypto,
        private readonly string $backupId,
        string $masterKey,
        string $backupType,
        array $publicMeta = [],
        array $portableKeySlots = []
    ) {
        if (!is_resource($this->handle)) {
            throw new StorageException('Backup stream is not writable.');
        }
        $this->dataKey = $crypto->randomKey();
        $this->noncePrefix = random_bytes(8);
        $header = [
            'format' => 'AVB',
            'version' => self::VERSION,
            'backup_id' => $backupId,
            'backup_type' => $backupType,
            'created_at' => gmdate('c'),
            'cipher' => CryptoService::CIPHER,
            'nonce_prefix' => base64_encode($this->noncePrefix),
            'wrapped_data_key' => $crypto->wrapKey($this->dataKey, $masterKey, 'astraea:vault:backup-key:' . $backupId),
            'portable_key_slots' => $portableKeySlots,
            'meta' => $publicMeta,
        ];
        $this->headerJson = json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->writeRaw(self::MAGIC);
        $this->writeRaw(pack('N', strlen($this->headerJson)));
        $this->writeRaw($this->headerJson);
        $this->writeRecord(self::TYPE_HEADER_AUTH, hash('sha256', $this->headerJson), false);
    }

    public function writeRecord(int $type, string $payload, bool $compress = false): void
    {
        if ($this->ended) {
            throw new StorageException('Cannot append records after container finalization.');
        }
        if ($type < 0 || $type > 255 || $type === self::TYPE_END && $this->counter === 0) {
            throw new ValidationException('Invalid backup record type.');
        }
        if ($this->counter >= self::MAX_COUNTER) {
            throw new StorageException('Backup record counter exhausted.');
        }
        $this->counter++;
        $body = $payload;
        $flag = "\x00";
        if ($compress && strlen($payload) >= 256) {
            $compressed = gzdeflate($payload, 6);
            if (is_string($compressed) && strlen($compressed) < strlen($payload)) {
                $body = $compressed;
                $flag = "\x01";
            }
        }
        $plaintext = $flag . $body;
        $nonce = $this->noncePrefix . pack('N', $this->counter);
        $aad = $this->aad($type, $this->counter);
        $box = $this->crypto->encryptWithNonce($plaintext, $this->dataKey, $nonce, $aad);
        $length = strlen($box['ciphertext']);
        if ($length > 16 * 1024 * 1024) {
            throw new StorageException('Encrypted record exceeded hard size limit.');
        }
        $frame = pack('CNN', $type, $this->counter, $length) . $box['tag'] . $box['ciphertext'];
        $this->writeRaw($frame);
    }

    public function finalize(array $manifest): void
    {
        if ($this->ended) {
            return;
        }
        $manifest['record_count_before_end'] = $this->counter;
        $payload = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->writeRecord(self::TYPE_END, $payload, true);
        $this->ended = true;
        if (!fflush($this->handle)) {
            throw new StorageException('Unable to flush backup stream.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->crypto->wipe($this->dataKey);
        $this->noncePrefix = '';
    }

    public function __destruct()
    {
        if (isset($this->dataKey) && $this->dataKey !== '') {
            $this->crypto->wipe($this->dataKey);
        }
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    private function aad(int $type, int $counter): string
    {
        return 'AVB1|' . $this->backupId . '|' . $type . '|' . $counter;
    }

    private function writeRaw(string $data): void
    {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($this->handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new StorageException('Backup stream write failed.');
            }
            $offset += $written;
        }
    }
}
