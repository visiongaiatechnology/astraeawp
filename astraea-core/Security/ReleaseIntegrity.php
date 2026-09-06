<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Security;

/**
 * Verifies release-manifest authenticity independently from file integrity.
 * No private key is ever shipped. A trusted Ed25519 public key may be embedded
 * later as ASTRAEA_RELEASE_PUBLIC_KEY or provisioned by the deployment layer.
 */
final class ReleaseIntegrity
{
    /** @return array{status:string,verified:bool,algorithm:string,key_id:string,details:string} */
    public static function verifyRootManifest(?string $manifestPath = null): array
    {
        $path = $manifestPath ?? (defined('ABSPATH') ? ABSPATH . 'BUILD-MANIFEST.json' : dirname(__DIR__, 2) . '/BUILD-MANIFEST.json');
        if (!is_file($path) || !is_readable($path)) {
            return self::result('MISSING', false, '', '', 'Root release manifest is unavailable.');
        }

        try {
            $raw = file_get_contents($path);
            $manifest = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            return self::result('INVALID', false, '', '', 'Root release manifest could not be parsed.');
        }
        if (!is_array($manifest)) {
            return self::result('INVALID', false, '', '', 'Root release manifest is invalid.');
        }

        $auth = $manifest['authenticity'] ?? null;
        if (!is_array($auth) || ($auth['status'] ?? '') !== 'signed') {
            return self::result('UNSIGNED', false, '', '', 'Local SHA-256 integrity is available; VGT release authenticity is not configured for this build.');
        }
        if (!extension_loaded('sodium')) {
            return self::result('UNVERIFIABLE', false, 'Ed25519', (string)($auth['key_id'] ?? ''), 'Sodium is unavailable for Ed25519 verification.');
        }

        $trustedKey = self::trustedPublicKey();
        if ($trustedKey === null) {
            return self::result('UNTRUSTED', false, 'Ed25519', (string)($auth['key_id'] ?? ''), 'Manifest is signed, but no trusted Astraea release public key is configured.');
        }

        $signature = isset($auth['signature']) && is_string($auth['signature']) ? base64_decode($auth['signature'], true) : false;
        if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return self::result('INVALID', false, 'Ed25519', (string)($auth['key_id'] ?? ''), 'Manifest signature encoding is invalid.');
        }

        $expectedKeyId = substr(hash('sha256', $trustedKey), 0, 16);
        $declaredKeyId = (string)($auth['key_id'] ?? '');
        if ($declaredKeyId === '' || !hash_equals($expectedKeyId, $declaredKeyId)) {
            return self::result('UNTRUSTED', false, 'Ed25519', $declaredKeyId, 'Manifest signing key identifier does not match the trusted Astraea release key.');
        }

        $payload = self::canonicalPayload($manifest);
        $verified = sodium_crypto_sign_verify_detached($signature, $payload, $trustedKey);
        return $verified
            ? self::result('VERIFIED', true, 'Ed25519', $declaredKeyId, 'VGT release manifest signature verified with the configured trusted Ed25519 public key.')
            : self::result('INVALID', false, 'Ed25519', $declaredKeyId, 'VGT release manifest signature verification failed.');
    }

    /** @param array<string,mixed> $manifest */
    public static function canonicalPayload(array $manifest): string
    {
        $project = (string)($manifest['project'] ?? '');
        $version = (string)($manifest['version'] ?? '');
        $corpus = is_array($manifest['security'] ?? null) ? (string)(($manifest['security']['corpus_sha256'] ?? '')) : '';
        $count = (int)($manifest['total_files'] ?? 0);
        return "ASTRAEA-RELEASE-V1\nproject={$project}\nversion={$version}\ncorpus_sha256={$corpus}\ntotal_files={$count}\n";
    }

    private static function trustedPublicKey(): ?string
    {
        $configured = defined('ASTRAEA_RELEASE_PUBLIC_KEY') && is_string(ASTRAEA_RELEASE_PUBLIC_KEY)
            ? ASTRAEA_RELEASE_PUBLIC_KEY
            : getenv('ASTRAEA_RELEASE_PUBLIC_KEY');
        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }
        $value = trim($configured);
        if (strlen($value) === 64 && ctype_xdigit($value)) {
            $decoded = hex2bin($value);
            return is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $decoded : null;
        }
        $decoded = base64_decode($value, true);
        return is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $decoded : null;
    }

    /** @return array{status:string,verified:bool,algorithm:string,key_id:string,details:string} */
    private static function result(string $status, bool $verified, string $algorithm, string $keyId, string $details): array
    {
        return ['status' => $status, 'verified' => $verified, 'algorithm' => $algorithm, 'key_id' => $keyId, 'details' => $details];
    }
}
