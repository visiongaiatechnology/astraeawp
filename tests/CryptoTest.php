<?php
declare(strict_types=1);

namespace Astraea\Tests;

use Astraea\Crypto\CryptoService;
use Astraea\Crypto\CryptoAuthenticationException;
use Astraea\Crypto\MasterKeyManager;
use Astraea\Crypto\KeyContext;

require_once __DIR__ . '/TestCase.php';

final class CryptoTest extends TestCase {

    public static function run(): void {
        echo "Running CryptoTest...\n";

        // Set test master key
        MasterKeyManager::setMasterKey('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');

        // 1. Basic Encryption & Decryption
        $plaintext = 'Secret Payload Data: AstraeaOS-WP Core Foundation 2026';
        $aad = 'options:jwt_secret:1';
        $envelope = CryptoService::encrypt($plaintext, KeyContext::DATABASE_OPTIONS, $aad);

        self::assertStringStartsWith('vgt:a1:', $envelope, 'Envelope must have vgt:a1: prefix');
        self::assertTrue(CryptoService::isEncrypted($envelope), 'isEncrypted must recognize envelope');

        $decrypted = CryptoService::decrypt($envelope, KeyContext::DATABASE_OPTIONS, $aad);
        self::assertEquals($plaintext, $decrypted, 'Decrypted plaintext must match original');

        // 2. AAD Tampering / Transplantation Detection
        $aadTampered = false;
        try {
            CryptoService::decrypt($envelope, KeyContext::DATABASE_OPTIONS, 'options:other_secret:2');
        } catch (CryptoAuthenticationException) {
            $aadTampered = true;
        }
        self::assertTrue($aadTampered, 'Decrypt must throw CryptoAuthenticationException on AAD mismatch');
        self::assertTrue(is_subclass_of(CryptoAuthenticationException::class, \Astraea\Exceptions\SecurityException::class), 'CryptoAuthenticationException must inherit from SecurityException');
        self::assertTrue(is_subclass_of(CryptoAuthenticationException::class, \Astraea\Exceptions\AppException::class), 'CryptoAuthenticationException must inherit from AppException');

        // 3. Context Separation Detection
        $contextTampered = false;
        try {
            CryptoService::decrypt($envelope, KeyContext::GEDEFENSE, $aad);
        } catch (CryptoAuthenticationException) {
            $contextTampered = true;
        }
        self::assertTrue($contextTampered, 'Decrypt must throw CryptoAuthenticationException on context mismatch');

        // 4. Ciphertext Bit Tampering Detection
        $char = $envelope[-5] === 'A' ? 'B' : 'A';
        $tamperedEnvelope = substr_replace($envelope, $char, -5, 1);
        $bitTampered = false;
        try {
            CryptoService::decrypt($tamperedEnvelope, KeyContext::DATABASE_OPTIONS, $aad);
        } catch (\Throwable) {
            $bitTampered = true;
        }
        self::assertTrue($bitTampered, 'Tampered ciphertext tag or payload must be rejected');

        // 6. Keyring Dynamic Key Rotation & Historical Decryption
        \Astraea\Crypto\Keyring::reset();
        $key1 = '1111111111111111111111111111111111111111111111111111111111111111';
        \Astraea\Crypto\Keyring::registerKey($key1, \Astraea\Crypto\KeyState::ACTIVE);

        $msg1 = 'Legacy confidential data encrypted under Key 1';
        $envelope1 = CryptoService::encrypt($msg1, KeyContext::SECRETS, 'aad:key1');
        self::assertEquals($msg1, CryptoService::decrypt($envelope1, KeyContext::SECRETS, 'aad:key1'));

        // Rotate to Key 2
        $key2 = '2222222222222222222222222222222222222222222222222222222222222222';
        $rotatedRecord = \Astraea\Crypto\Keyring::rotateKey($key2);
        self::assertEquals(\Astraea\Crypto\KeyState::ACTIVE, $rotatedRecord->state);

        // Encrypt new data under Key 2
        $msg2 = 'New confidential data encrypted under Key 2';
        $envelope2 = CryptoService::encrypt($msg2, KeyContext::SECRETS, 'aad:key2');

        // Verify: Old data remains decryptable under Key 1 (DECRYPT_ONLY)
        self::assertEquals($msg1, CryptoService::decrypt($envelope1, KeyContext::SECRETS, 'aad:key1'), 'Historical data must remain decryptable after key rotation');
        // Verify: New data decrypts under Key 2 (ACTIVE)
        self::assertEquals($msg2, CryptoService::decrypt($envelope2, KeyContext::SECRETS, 'aad:key2'), 'New data must decrypt with active key');

        // Test Revocation: Revoke Key 1
        $key1Id = substr(hash('sha256', hex2bin($key1)), 0, 8);
        \Astraea\Crypto\Keyring::revokeKey($key1Id);

        $revocationBlocked = false;
        try {
            CryptoService::decrypt($envelope1, KeyContext::SECRETS, 'aad:key1');
        } catch (\Astraea\Exceptions\SecurityException) {
            $revocationBlocked = true;
        }
        self::assertTrue($revocationBlocked, 'Decryption with REVOKED key must be strictly blocked with SecurityException');

        // Key 2 data must still decrypt
        self::assertEquals($msg2, CryptoService::decrypt($envelope2, KeyContext::SECRETS, 'aad:key2'));

        // 7. HKDF Context Derivation Uniqueness
        $keyOptions = MasterKeyManager::deriveSubkey(KeyContext::DATABASE_OPTIONS);
        $keyGeDefense = MasterKeyManager::deriveSubkey(KeyContext::GEDEFENSE);
        self::assertTrue($keyOptions !== $keyGeDefense, 'Subkeys derived for distinct contexts must be mathematically distinct');
        self::assertEquals(32, strlen($keyOptions), 'Subkey length must be 32 bytes');

        // 8. Keyring Resolution of Nonexistent Key (CryptoAuthenticationException test)
        $nonexistentCaught = false;
        try {
            \Astraea\Crypto\Keyring::resolveKey('deadbeef');
        } catch (CryptoAuthenticationException) {
            $nonexistentCaught = true;
        }
        self::assertTrue($nonexistentCaught, 'Keyring::resolveKey on unknown keyId must throw CryptoAuthenticationException');

        echo "  -> CryptoTest completed.\n";
    }
}
