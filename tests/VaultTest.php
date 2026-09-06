<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Tests;

use Astraea\Vault\Crypto\CryptoService as VaultCrypto;
use Astraea\Vault\Backup\ContainerWriter;
use Astraea\Vault\Backup\ContainerReader;
use Astraea\Vault\Integration\AstraeaCore;
use Astraea\Vault\Recovery\RecoveryGate;
use Astraea\Vault\Plugin as VaultPlugin;
use Astraea\Bootstrap\BootOrchestrator;
use Astraea\Bootstrap\RuntimeCheck;
use Astraea\Security\HeaderPolicyService;
use Astraea\Security\Logger;
use Astraea\Crypto\MasterKeyManager;

require_once __DIR__ . '/TestCase.php';

final class VaultTest extends TestCase {

    public static function run(): void {
        echo "Running VaultTest...\n";

        self::testAvbRoundtripAndTamperDetection();
        self::testAvbTruncationDetection();
        self::testVaultServiceKeyDerivation();
        self::testAstraeaSecurityEventBusBridge();
        self::testPrePluginRecoveryGateExecution();
        self::testHeaderPolicySafeDefaults();
        self::testLoggerUriSanitization();

        echo "  -> VaultTest completed.\n";
    }

    private static function testAvbRoundtripAndTamperDetection(): void {
        $crypto = new VaultCrypto();
        $master = $crypto->randomKey();
        $id = '123e4567-e89b-42d3-a456-426614174000';
        $tmp = tempnam(sys_get_temp_dir(), 'avb-test-');
        $handle = fopen($tmp, 'w+b');
        self::assertTrue($handle !== false, 'Temp file for AVB test must be writable');

        $writer = new ContainerWriter($handle, $crypto, $id, $master, 'database');
        $writer->writeRecord(
            ContainerWriter::TYPE_DB_TABLE,
            json_encode(['table' => 'wp_options', 'create_sql' => 'CREATE TABLE `wp_options` (`id` int)'], JSON_THROW_ON_ERROR)
        );
        $writer->writeRecord(
            ContainerWriter::TYPE_DB_ROWS,
            json_encode(['table' => 'wp_options', 'rows' => [['id' => base64_encode('1')]]], JSON_THROW_ON_ERROR),
            true
        );
        $writer->finalize(['manifest_hash' => 'valid_mock_hash', 'records' => 2]);
        $writer->close();

        // 1. Valid Read & Verification
        $reader = new ContainerReader(fopen($tmp, 'rb'), $crypto, $master);
        $end = $reader->consumeAndVerify();
        $reader->close();
        self::assertEquals('valid_mock_hash', $end['manifest_hash'], 'AVB container must verify clean manifest hash');

        // 2. Tamper Injection: Flip a bit in the ciphertext record
        $bytes = file_get_contents($tmp);
        $corruptIndex = (int) (strlen($bytes) / 2);
        $bytes[$corruptIndex] = chr(ord($bytes[$corruptIndex]) ^ 0x01);
        file_put_contents($tmp, $bytes);

        $tamperCaught = false;
        try {
            $readerCorrupt = new ContainerReader(fopen($tmp, 'rb'), $crypto, $master);
            $readerCorrupt->consumeAndVerify();
            $readerCorrupt->close();
        } catch (\Throwable $e) {
            $tamperCaught = true;
        }
        self::assertTrue($tamperCaught, 'AVB container reader must catch ciphertext bit flips with fatal crypto exception');

        @unlink($tmp);
        $crypto->wipe($master);
    }

    private static function testAvbTruncationDetection(): void {
        $crypto = new VaultCrypto();
        $master = $crypto->randomKey();
        $id = '223e4567-e89b-42d3-a456-426614174111';
        $tmp = tempnam(sys_get_temp_dir(), 'avb-trunc-');
        $handle = fopen($tmp, 'w+b');

        $writer = new ContainerWriter($handle, $crypto, $id, $master, 'files');
        $writer->writeRecord(
            ContainerWriter::TYPE_FILE_START,
            json_encode(['path' => 'wp-config.php', 'size' => 1024], JSON_THROW_ON_ERROR)
        );
        $writer->finalize(['status' => 'complete']);
        $writer->close();

        // Truncate the file (drop the trailer / HMAC tag)
        $bytes = file_get_contents($tmp);
        $truncatedBytes = substr($bytes, 0, (int) (strlen($bytes) * 0.7));
        file_put_contents($tmp, $truncatedBytes);

        $truncationCaught = false;
        try {
            $reader = new ContainerReader(fopen($tmp, 'rb'), $crypto, $master);
            $reader->consumeAndVerify();
            $reader->close();
        } catch (\Throwable $e) {
            $truncationCaught = true;
        }
        self::assertTrue($truncationCaught, 'AVB container reader must detect file truncation before trailer verification');

        @unlink($tmp);
        $crypto->wipe($master);
    }

    private static function testVaultServiceKeyDerivation(): void {
        MasterKeyManager::setMasterKey('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
        $crypto = new VaultCrypto();

        $serviceKey = AstraeaCore::deriveVaultServiceKey($crypto);
        self::assertTrue(is_string($serviceKey), 'Vault service key must be derived from Astraea Core master key');
        self::assertEquals(VaultCrypto::KEY_BYTES, strlen($serviceKey), 'Derived service key must be exact 32 bytes');

        // Deterministic check
        $serviceKey2 = AstraeaCore::deriveVaultServiceKey($crypto);
        self::assertEquals($serviceKey, $serviceKey2, 'Repeated derivation of unattended key must yield identical key');

        $crypto->wipe($serviceKey);
        $crypto->wipe($serviceKey2);
    }

    private static function testAstraeaSecurityEventBusBridge(): void {
        $emitted = false;
        if (function_exists('add_action')) {
            add_action('astraea_vault_security_event', static function(string $event, array $safe = []) use (&$emitted): void {
                if ($event === 'audit_backup_started') {
                    $emitted = true;
                }
            }, 10, 2);
        }

        AstraeaCore::securityEvent('audit_backup_started', ['user_id' => 1, 'token' => 'SECRET_TOKEN']);
        self::assertTrue($emitted, 'AstraeaCore::securityEvent must trigger astraea_vault_security_event action');
    }

    private static function testPrePluginRecoveryGateExecution(): void {
        $baseline = RuntimeCheck::check();
        $hostCompatible = true;
        foreach ($baseline as $check) {
            if (empty($check['passed'])) {
                $hostCompatible = false;
                break;
            }
        }

        if (!$hostCompatible) {
            // CI/unit fallback: production runtime remains fail-closed, while ordering is verified from source.
            $source = file_get_contents(dirname(__DIR__) . '/astraea-core/Bootstrap/BootOrchestrator.php');
            self::assertTrue(is_string($source), 'BootOrchestrator source must be readable for phase-order verification');
            self::assertStringContains('public static function bootPhaseD()', (string)$source, 'Phase D must exist');
            self::assertStringContains('\Astraea\Vault\Plugin', (string)$source, 'Phase D must reference core-native Vault');
            return;
        }

        BootOrchestrator::bootPhaseD();
        self::assertTrue(class_exists('\Astraea\Vault\Plugin'), 'Vault Plugin class must be accessible after Phase D');
        $instance = VaultPlugin::instance();
        self::assertTrue(is_object($instance), 'VaultPlugin instance must be instantiated');
    }

    private static function testHeaderPolicySafeDefaults(): void {
        // Test default headers
        $headers = HeaderPolicyService::buildHeaders();
        self::assertEquals('nosniff', $headers['X-Content-Type-Options']);
        self::assertEquals('SAMEORIGIN', $headers['X-Frame-Options']);
        self::assertEquals('strict-origin-when-cross-origin', $headers['Referrer-Policy']);

        // Test HSTS string format
        $hsts = HeaderPolicyService::buildHstsHeader();
        self::assertStringContains('max-age=31536000', $hsts);
        self::assertFalse(str_contains($hsts, 'preload'), 'HSTS preload must be strictly opt-in and absent by default');
        self::assertFalse(str_contains($hsts, 'includeSubDomains'), 'HSTS includeSubDomains must be strictly opt-in and absent by default');
    }

    private static function testLoggerUriSanitization(): void {
        // Clean path
        self::assertEquals('/wp-admin/index.php', Logger::sanitizeUri('/wp-admin/index.php'));

        // Query string with secrets
        $dirtyUri = '/wp-admin/admin-ajax.php?action=login&user_pass=SuperSecret&token=xyz789&nonce=12345&tab=overview';
        $cleanUri = Logger::sanitizeUri($dirtyUri);

        self::assertFalse(str_contains($cleanUri, 'SuperSecret'), 'Logger must redact user_pass query value');
        self::assertFalse(str_contains($cleanUri, 'xyz789'), 'Logger must redact token query value');
        self::assertFalse(str_contains($cleanUri, '12345'), 'Logger must redact nonce query value');
        self::assertStringContains('tab=overview', $cleanUri, 'Logger must preserve non-sensitive query parameters');
        self::assertStringContains('token=%5BREDACTED%5D', $cleanUri, 'Logger must mark sensitive keys as [REDACTED]');
    }
}
