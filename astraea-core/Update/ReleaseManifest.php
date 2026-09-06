<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Update;

use Astraea\Exceptions\ValidationException;

/**
 * Validated, typed representation of an Astraea Release Manifest.
 *
 * @package Astraea\Update
 */
final class ReleaseManifest {
    /**
     * @param string $product Expected 'astraeaos-wp'.
     * @param string $version Release semver string.
     * @param ReleaseChannel $channel Release channel.
     * @param string $minimumPhp Minimum required PHP version.
     * @param string $minimumDatabase Minimum required Database version.
     * @param string $packageUrl HTTPS package download URL.
     * @param string $sha256 Expected SHA-256 hex checksum of the package.
     * @param int $releaseTimestamp Unix release creation time.
     * @param string $schemaVersion Astraea schema version.
     * @param string $migrationVersion Required DB migration version.
     * @param string|null $signature Base64-encoded Ed25519 signature.
     * @param string|null $keyId Ed25519 public key identifier (first 16 hex chars).
     * @param string $releaseNotesUrl URL or reference to release notes.
     * @param array<string, mixed> $raw Original decoded payload.
     */
    public function __construct(
        public readonly string $product,
        public readonly string $version,
        public readonly ReleaseChannel $channel,
        public readonly string $minimumPhp,
        public readonly string $minimumDatabase,
        public readonly string $packageUrl,
        public readonly string $sha256,
        public readonly int $releaseTimestamp,
        public readonly string $schemaVersion,
        public readonly string $migrationVersion,
        public readonly ?string $signature = null,
        public readonly ?string $keyId = null,
        public readonly string $releaseNotesUrl = '',
        public readonly array $raw = []
    ) {}

    /**
     * Parse and validate manifest from raw array.
     *
     * @param array<string, mixed> $data
     * @return self
     * @throws ValidationException If required fields are missing or invalid.
     */
    public static function fromArray(array $data): self {
        $product = trim((string)($data['product'] ?? ''));
        if ($product !== 'astraeaos-wp' && $product !== 'AstraeaOS WP') {
            throw new ValidationException('Invalid manifest: Product must be "astraeaos-wp".');
        }

        $version = trim((string)($data['version'] ?? ''));
        if ($version === '' || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.]+)?$/', $version)) {
            throw new ValidationException('Invalid manifest: Version string violates semver format.');
        }

        $rawChannel = (string)($data['channel'] ?? 'stable');
        $channel = ReleaseChannel::tryFrom(strtolower($rawChannel)) ?? ReleaseChannel::STABLE;

        $minPhp = trim((string)($data['minimum_php'] ?? '8.2.0'));
        $minDb  = trim((string)($data['minimum_database'] ?? '8.0.0'));

        $packageUrl = trim((string)($data['package_url'] ?? ''));
        if ($packageUrl === '' || (!str_starts_with($packageUrl, 'https://') && !str_starts_with($packageUrl, 'file://'))) {
            throw new ValidationException('Invalid manifest: Package URL must use secure transport.');
        }

        $sha256 = strtolower(trim((string)($data['sha256'] ?? '')));
        if (strlen($sha256) !== 64 || !ctype_xdigit($sha256)) {
            throw new ValidationException('Invalid manifest: Expected 64-character SHA-256 checksum.');
        }

        $timestamp = (int)($data['release_timestamp'] ?? 0);
        $schemaVer = trim((string)($data['schema_version'] ?? '1.0.0'));
        $migVer    = trim((string)($data['migration_version'] ?? '1.0.0'));

        $auth = is_array($data['authenticity'] ?? null) ? $data['authenticity'] : [];
        $signature = isset($auth['signature']) && is_string($auth['signature']) ? trim($auth['signature']) : null;
        $keyId = isset($auth['key_id']) && is_string($auth['key_id']) ? trim($auth['key_id']) : null;
        $notes = trim((string)($data['release_notes_url'] ?? ''));

        return new self(
            product: $product,
            version: $version,
            channel: $channel,
            minimumPhp: $minPhp,
            minimumDatabase: $minDb,
            packageUrl: $packageUrl,
            sha256: $sha256,
            releaseTimestamp: $timestamp,
            schemaVersion: $schemaVer,
            migrationVersion: $migVer,
            signature: $signature,
            keyId: $keyId,
            releaseNotesUrl: $notes,
            raw: $data
        );
    }

    /**
     * Canonical string payload used for detached Ed25519 signature verification.
     */
    public function canonicalPayload(): string {
        return sprintf(
            "ASTRAEA-UPDATE-MANIFEST-V1\nproduct=%s\nversion=%s\nchannel=%s\nmin_php=%s\nmin_db=%s\nsha256=%s\ntimestamp=%d\n",
            $this->product,
            $this->version,
            $this->channel->value,
            $this->minimumPhp,
            $this->minimumDatabase,
            $this->sha256,
            $this->releaseTimestamp
        );
    }
}
