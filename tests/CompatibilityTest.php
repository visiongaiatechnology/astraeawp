<?php
declare(strict_types=1);

namespace Astraea\Tests;

use Astraea\Performance\LegacyPruner;

require_once __DIR__ . '/TestCase.php';

final class CompatibilityTest extends TestCase {

    public static function run(): void {
        echo "Running CompatibilityTest...\n";

        // 1. Pluggable Password Function Overrides
        self::assertTrue(function_exists('wp_hash_password'), 'wp_hash_password must be defined');
        self::assertTrue(function_exists('wp_check_password'), 'wp_check_password must be defined');
        self::assertTrue(function_exists('wp_password_needs_rehash'), 'wp_password_needs_rehash must be defined');

        $samplePass = 'WordPressCompatiblePassword#2026';
        $hash = wp_hash_password($samplePass);
        self::assertStringStartsWith('$argon2id$', $hash, 'wp_hash_password must output Argon2id');
        self::assertTrue(wp_check_password($samplePass, $hash), 'wp_check_password must verify valid password');
        self::assertFalse(wp_check_password('WrongPass', $hash), 'wp_check_password must reject invalid password');
        self::assertFalse(wp_password_needs_rehash($hash), 'Fresh Argon2id hash must not need rehash');

        // 2. Fork Version & Constant Identity
        self::assertTrue(defined('ASTRAEA_VERSION'), 'ASTRAEA_VERSION must be defined');
        self::assertEquals(\Astraea\Version::VERSION, \ASTRAEA_VERSION, 'ASTRAEA_VERSION must match the distribution Version source of truth');

        // 3. Legacy Pruner Defaults
        self::assertFalse(LegacyPruner::isXmlRpcEnabled(), 'XML-RPC must be disabled by default');
        self::assertFalse(LegacyPruner::isPingbackEnabled(), 'Pingbacks must be disabled by default');
        self::assertFalse(LegacyPruner::isEmojiEnabled(), 'Emojis bloat must be disabled by default');

        // 4. Procedural Crypto Helpers
        self::assertTrue(function_exists('astraea_encrypt_secret'), 'astraea_encrypt_secret must be defined');
        self::assertTrue(function_exists('astraea_decrypt_secret'), 'astraea_decrypt_secret must be defined');

        $token = 'proc_secret_test_token_99';
        $encrypted = astraea_encrypt_secret($token, 'options', 'test:aad:1');
        self::assertStringStartsWith('vgt:a1:', $encrypted, 'Procedural helper must produce valid vgt:a1 envelope');
        $decrypted = astraea_decrypt_secret($encrypted, 'options', 'test:aad:1');
        self::assertEquals($token, $decrypted, 'Procedural decrypt must restore original token');

        echo "  -> CompatibilityTest completed.\n";
    }
}
