<?php
declare(strict_types=1);

namespace Astraea\Tests;

use Astraea\Auth\PasswordService;
use Astraea\Auth\PepperManager;
use Astraea\Auth\LegacyHashVerifier;
use Astraea\Auth\Argon2idPolicy;

require_once __DIR__ . '/TestCase.php';

final class AuthTest extends TestCase {

    public static function run(): void {
        echo "Running AuthTest...\n";

        // 1. Password hashing with Argon2id
        $password = 'AstraeaSecurePass_#2026!';
        $hash = PasswordService::hash($password);

        self::assertStringStartsWith('$argon2id$', $hash, 'Hash must be in native Argon2id format');
        self::assertTrue(PasswordService::verify($password, $hash), 'PasswordService::verify must succeed for valid Argon2id hash');
        self::assertFalse(PasswordService::verify('WrongPassword_123', $hash), 'PasswordService::verify must fail for wrong password');
        self::assertFalse(PasswordService::needsRehash($hash), 'Fresh Argon2id hash must not need rehash');

        // 2. Legacy MD5 verification & rehash detection
        $legacyMd5Hash = md5($password);
        self::assertTrue(LegacyHashVerifier::isLegacyHash($legacyMd5Hash), 'MD5 must be detected as legacy');
        self::assertTrue(PasswordService::verify($password, $legacyMd5Hash), 'MD5 legacy hash must verify correctly');
        self::assertTrue(PasswordService::needsRehash($legacyMd5Hash), 'MD5 hash must flag needsRehash as true');

        // 3. Legacy $wp-prefixed Bcrypt verification & rehash detection
        $wpBcryptPayload = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
        $wpBcryptHash = '$wp' . password_hash($wpBcryptPayload, PASSWORD_BCRYPT);
        self::assertTrue(LegacyHashVerifier::isLegacyHash($wpBcryptHash), '$wp bcrypt must be detected as legacy');
        self::assertTrue(PasswordService::verify($password, $wpBcryptHash), '$wp bcrypt must verify correctly');
        self::assertTrue(PasswordService::needsRehash($wpBcryptHash), '$wp bcrypt must flag needsRehash as true');

        // 4. Standard Bcrypt verification
        $stdBcryptHash = password_hash($password, PASSWORD_BCRYPT);
        self::assertTrue(LegacyHashVerifier::isLegacyHash($stdBcryptHash), 'Standard bcrypt must be detected as legacy');
        self::assertTrue(PasswordService::verify($password, $stdBcryptHash), 'Standard bcrypt must verify correctly');
        self::assertTrue(PasswordService::needsRehash($stdBcryptHash), 'Standard bcrypt must flag needsRehash as true');

        // 5. Server-side Pepper test
        PepperManager::setPepper('SUPER_SECRET_EXTERNAL_PEPPER_KEY_32B');
        $pepperedHash = PasswordService::hash($password);
        self::assertStringStartsWith('$argon2id$', $pepperedHash, 'Peppered hash must be Argon2id');
        self::assertTrue(PasswordService::verify($password, $pepperedHash), 'Peppered hash must verify with pepper enabled');
        
        // Reset pepper
        PepperManager::setPepper(null);

        // 6. Argon2id Policy bounds check
        $options = Argon2idPolicy::getOptions();
        self::assertTrue($options['memory_cost'] >= Argon2idPolicy::MIN_MEMORY_COST, 'Memory cost must respect minimum bound');
        self::assertTrue($options['time_cost'] >= Argon2idPolicy::MIN_TIME_COST, 'Time cost must respect minimum bound');
        self::assertTrue($options['threads'] >= Argon2idPolicy::MIN_THREADS, 'Threads must respect minimum bound');

        // 7. Whitespace Preservation in Passwords (Zero unintended trim)
        $passWithSpaces = '   untrimmed diceware password with leading and trailing spaces   ';
        $hashWithSpaces = PasswordService::hash($passWithSpaces);
        self::assertTrue(PasswordService::verify($passWithSpaces, $hashWithSpaces), 'Exact password with whitespace must verify');
        self::assertFalse(PasswordService::verify(trim($passWithSpaces), $hashWithSpaces), 'Trimmed password must NOT verify against untrimmed hash');

        echo "  -> AuthTest completed.\n";
    }
}
