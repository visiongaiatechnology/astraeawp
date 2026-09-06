<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Update;

use Astraea\Version;
use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;

/**
 * Cryptographic and Environment Verifier for Astraea Updates.
 *
 * Enforces Ed25519 signature checks, minimum host baselines, and downgrade prevention.
 *
 * @package Astraea\Update
 */
final class UpdateVerifier {

    /**
     * VGT Official Public Key for AstraeaOS Release Verification.
     * Hex-encoded 32-byte Ed25519 public key.
     * Private key is stored strictly offline in air-gapped VGT release vault.
     */
    public const DEFAULT_PUBLIC_KEY_HEX = 'e9c7bb7c24f60d5b4a68ef3381a171be9b165b5cb4e6d421714dbfb305c4a5c9';

    /**
     * Verify Release Manifest integrity, environment compatibility, and authenticity.
     *
     * @param ReleaseManifest $manifest
     * @param bool $allowDowngrade Only true during recovery rollback
     * @return AuthenticityStatus
     * @throws SecurityException If security policy or downgrade check is violated.
     * @throws ValidationException If environment baseline fails.
     */
    public static function verify(ReleaseManifest $manifest, bool $allowDowngrade = false): AuthenticityStatus {
        // 1. Host PHP Version Check
        if (version_compare(PHP_VERSION, $manifest->minimumPhp, '<')) {
            throw new ValidationException(sprintf(
                'Host PHP version %s does not meet update requirement %s.',
                PHP_VERSION,
                $manifest->minimumPhp
            ));
        }

        // 2. Database Version Check (if DB available)
        global $wpdb;
        if (isset($wpdb) && $wpdb instanceof \wpdb && !empty($wpdb->dbh)) {
            $dbServerVer = $wpdb->db_version();
            $cleanVer = preg_replace('/[^0-9.]/', '', $dbServerVer) ?? '';
            if ($cleanVer !== '' && version_compare($cleanVer, $manifest->minimumDatabase, '<')) {
                throw new ValidationException(sprintf(
                    'Host Database version %s does not meet update requirement %s.',
                    $cleanVer,
                    $manifest->minimumDatabase
                ));
            }
        }

        // 3. Downgrade Attack Prevention
        $currentVersion = Version::VERSION;
        if (!$allowDowngrade && version_compare($manifest->version, $currentVersion, '<')) {
            throw new SecurityException(sprintf(
                'Downgrade attack rejected: Target version %s is older than active version %s.',
                $manifest->version,
                $currentVersion
            ));
        }

        // 4. Ed25519 Authenticity Verification
        return self::verifySignature($manifest);
    }

    /**
     * Verify Ed25519 detached signature on release manifest.
     */
    public static function verifySignature(ReleaseManifest $manifest): AuthenticityStatus {
        if ($manifest->signature === null || trim($manifest->signature) === '') {
            return AuthenticityStatus::UNSIGNED;
        }

        if (!extension_loaded('sodium')) {
            return AuthenticityStatus::UNKNOWN;
        }

        $publicKeyBin = self::getTrustedPublicKey();
        if ($publicKeyBin === null) {
            return AuthenticityStatus::UNKNOWN;
        }

        $signatureBin = base64_decode($manifest->signature, true);
        if (!is_string($signatureBin) || strlen($signatureBin) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return AuthenticityStatus::SIGNED_INVALID;
        }

        // Validate Key ID matches public key
        $expectedKeyId = substr(hash('sha256', $publicKeyBin), 0, 16);
        if ($manifest->keyId !== null && !hash_equals($expectedKeyId, $manifest->keyId)) {
            return AuthenticityStatus::SIGNED_INVALID;
        }

        $payload = $manifest->canonicalPayload();
        $valid = sodium_crypto_sign_verify_detached($signatureBin, $payload, $publicKeyBin);

        return $valid ? AuthenticityStatus::SIGNED_VERIFIED : AuthenticityStatus::SIGNED_INVALID;
    }

    /**
     * Retrieve trusted Ed25519 binary public key.
     */
    public static function getTrustedPublicKey(): ?string {
        $envKey = defined('ASTRAEA_RELEASE_PUBLIC_KEY') && is_string(ASTRAEA_RELEASE_PUBLIC_KEY)
            ? ASTRAEA_RELEASE_PUBLIC_KEY
            : getenv('ASTRAEA_RELEASE_PUBLIC_KEY');

        $hex = (is_string($envKey) && strlen(trim($envKey)) === 64 && ctype_xdigit(trim($envKey)))
            ? trim($envKey)
            : self::DEFAULT_PUBLIC_KEY_HEX;

        $bin = hex2bin($hex);
        return (is_string($bin) && strlen($bin) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) ? $bin : null;
    }
}
