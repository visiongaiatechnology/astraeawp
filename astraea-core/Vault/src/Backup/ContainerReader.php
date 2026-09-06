<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Backup;

use Astraea\Vault\Crypto\CryptoService;
use Astraea\Vault\Exception\CryptoException;
use Astraea\Vault\Exception\StorageException;

final class ContainerReader
{
    private array $header;
    private string $headerJson;
    private string $dataKey;
    private string $noncePrefix;
    private int $expectedCounter = 1;
    private bool $headerAuthenticated = false;
    private bool $ended = false;

    public function __construct(
        private $handle,
        private readonly CryptoService $crypto,
        string $masterKey
    ) {
        if (!is_resource($this->handle)) {
            throw new StorageException('Backup stream is not readable.');
        }
        $magic = $this->readExact(strlen(ContainerWriter::MAGIC));
        if (!hash_equals(ContainerWriter::MAGIC, $magic)) {
            throw new CryptoException('Backup container magic validation failed.');
        }
        $headerLength = unpack('Nlen', $this->readExact(4));
        $length = (int)($headerLength['len'] ?? 0);
        if ($length < 32 || $length > 65536) {
            throw new CryptoException('Backup header length validation failed.');
        }
        $this->headerJson = $this->readExact($length);
        $decoded = json_decode($this->headerJson, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['format'] ?? '') !== 'AVB' || (int)($decoded['version'] ?? 0) !== ContainerWriter::VERSION) {
            throw new CryptoException('Backup header validation failed.');
        }
        $id = (string)($decoded['backup_id'] ?? '');
        if (preg_match('/^[a-f0-9-]{36}$/D', $id) !== 1) {
            throw new CryptoException('Backup identifier validation failed.');
        }
        if (($decoded['cipher'] ?? '') !== CryptoService::CIPHER) {
            throw new CryptoException('Unsupported backup cipher.');
        }
        $prefix = base64_decode((string)($decoded['nonce_prefix'] ?? ''), true);
        if ($prefix === false || strlen($prefix) !== 8) {
            throw new CryptoException('Backup nonce prefix validation failed.');
        }
        $this->header = $decoded;
        $this->noncePrefix = $prefix;
        $this->dataKey = $crypto->unwrapKey((array)($decoded['wrapped_data_key'] ?? []), $masterKey, 'astraea:vault:backup-key:' . $id);
    }

    public function header(): array
    {
        return $this->header;
    }

    public function next(): ?array
    {
        if ($this->ended) {
            return null;
        }
        $first = fread($this->handle, 1);
        if ($first === false) {
            throw new StorageException('Backup stream read failed.');
        }
        if ($first === '') {
            throw new CryptoException('Backup container was truncated before final record.');
        }
        $restHeader = $this->readExact(8);
        $parts = unpack('Ctype/Ncounter/Nlength', $first . $restHeader);
        $type = (int)($parts['type'] ?? -1);
        $counter = (int)($parts['counter'] ?? 0);
        $length = (int)($parts['length'] ?? 0);
        if ($counter !== $this->expectedCounter) {
            throw new CryptoException('Backup record sequence validation failed.');
        }
        if ($length < 1 || $length > 16 * 1024 * 1024) {
            throw new CryptoException('Backup record length validation failed.');
        }
        $tag = $this->readExact(CryptoService::TAG_BYTES);
        $ciphertext = $this->readExact($length);
        $nonce = $this->noncePrefix . pack('N', $counter);
        $aad = 'AVB1|' . $this->header['backup_id'] . '|' . $type . '|' . $counter;
        $plaintext = $this->crypto->decrypt($ciphertext, $this->dataKey, $nonce, $tag, $aad);
        if ($plaintext === '') {
            throw new CryptoException('Backup record payload validation failed.');
        }
        $flag = ord($plaintext[0]);
        $payload = substr($plaintext, 1);
        if ($flag === 1) {
            $inflated = gzinflate($payload, 32 * 1024 * 1024);
            if ($inflated === false) {
                throw new CryptoException('Compressed backup record validation failed.');
            }
            $payload = $inflated;
        } elseif ($flag !== 0) {
            throw new CryptoException('Backup compression flag validation failed.');
        }

        if (!$this->headerAuthenticated) {
            if ($type !== ContainerWriter::TYPE_HEADER_AUTH || !hash_equals(hash('sha256', $this->headerJson), $payload)) {
                throw new CryptoException('Backup header authentication failed.');
            }
            $this->headerAuthenticated = true;
        } elseif ($type === ContainerWriter::TYPE_HEADER_AUTH) {
            throw new CryptoException('Duplicate backup header authentication record.');
        }

        $this->expectedCounter++;
        if ($type === ContainerWriter::TYPE_END) {
            $manifest = json_decode($payload, true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || (int)($manifest['record_count_before_end'] ?? -1) !== $counter - 1) {
                throw new CryptoException('Backup final manifest validation failed.');
            }
            $trailing = fread($this->handle, 1);
            if ($trailing !== '' && $trailing !== false) {
                throw new CryptoException('Unexpected trailing data after final backup record.');
            }
            $this->ended = true;
        }
        return ['type' => $type, 'counter' => $counter, 'payload' => $payload];
    }

    public function consumeAndVerify(): array
    {
        $end = [];
        while (($record = $this->next()) !== null) {
            if ($record['type'] === ContainerWriter::TYPE_END) {
                $decoded = json_decode($record['payload'], true, 128, JSON_THROW_ON_ERROR);
                $end = is_array($decoded) ? $decoded : [];
            }
        }
        if (!$this->ended) {
            throw new CryptoException('Backup verification did not reach final record.');
        }
        return $end;
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

    private function readExact(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->handle, $length - strlen($data));
            if ($chunk === false) {
                throw new StorageException('Backup stream read failed.');
            }
            if ($chunk === '') {
                throw new CryptoException('Backup container is truncated.');
            }
            $data .= $chunk;
        }
        return $data;
    }
}
