<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Tests;

use Astraea\Security\Random;
use Astraea\Security\FileGuard;
use Astraea\Security\Logger;
use Astraea\Security\HeaderPolicyService;
use Astraea\Security\AtomicCounter;
use Astraea\Exceptions\ValidationException;
use Astraea\Exceptions\SecurityException;

require_once __DIR__ . '/TestCase.php';

final class SecurityTest extends TestCase {

    public static function run(): void {
        echo "Running SecurityTest...\n";

        // 1. CSPRNG Randomness
        $token1 = Random::token(32);
        $token2 = Random::token(32);
        self::assertTrue($token1 !== $token2, 'Two generated CSPRNG tokens must be distinct');
        self::assertTrue(strlen($token1) >= 32, 'Token must have adequate length');

        $hex = Random::hex(16);
        self::assertEquals(32, strlen($hex), '16 bytes hex must produce 32 chars');
        self::assertTrue(ctype_xdigit($hex), 'Hex token must be valid hex');

        // Create temporary real file for upload tests
        $tempFile = tempnam(sys_get_temp_dir(), 'vgt_sec_test_');
        file_put_contents($tempFile, 'Test payload content for upload validation');

        // 2. FileGuard Upload Sanitization & VGT Pattern 1.5.A Opaque Catch
        // 2a. Double Extension Inspection
        $badFile1 = ['name' => 'malicious.php.jpg', 'tmp_name' => $tempFile];
        $inspected1 = FileGuard::inspectUpload($badFile1);
        self::assertEquals('Request rejected for security reasons.', $inspected1['error'] ?? '', 'Double extension .php.jpg must trigger opaque security rejection');

        $caughtDoubleExt = false;
        try {
            FileGuard::validateUploadFile($badFile1);
        } catch (SecurityException $e) {
            $caughtDoubleExt = str_contains($e->getMessage(), 'prohibited executable extension');
        }
        self::assertTrue($caughtDoubleExt, 'validateUploadFile must throw SecurityException for double extension');

        // 2b. Directory Traversal
        $badFile3 = ['name' => '../../traversal.jpg', 'tmp_name' => $tempFile];
        $inspected3 = FileGuard::inspectUpload($badFile3);
        self::assertEquals('Request rejected for security reasons.', $inspected3['error'] ?? '', 'Path traversal filename must trigger opaque security rejection');

        $caughtTraversal = false;
        try {
            FileGuard::validateUploadFile($badFile3);
        } catch (SecurityException $e) {
            $caughtTraversal = str_contains($e->getMessage(), 'traversal');
        }
        self::assertTrue($caughtTraversal, 'validateUploadFile must throw SecurityException on path traversal');

        // 2c. Direct executable extension
        $badFile4 = ['name' => 'shell.phar', 'tmp_name' => $tempFile];
        $inspected4 = FileGuard::inspectUpload($badFile4);
        self::assertEquals('Request rejected for security reasons.', $inspected4['error'] ?? '', '.phar file upload must trigger opaque security rejection');

        $caughtPhar = false;
        try {
            FileGuard::validateUploadFile($badFile4);
        } catch (SecurityException $e) {
            $caughtPhar = str_contains($e->getMessage(), 'forbidden');
        }
        self::assertTrue($caughtPhar, 'validateUploadFile must throw SecurityException for forbidden executable');

        // 2d. Clean legitimate file
        $goodFile = ['name' => 'document_photo.txt', 'tmp_name' => $tempFile];
        $inspectedGood = FileGuard::inspectUpload($goodFile);
        self::assertTrue(!isset($inspectedGood['error']), 'Clean file must pass inspection');

        // 3. VGT PATTERN 1.5.B — File Size Pre-Flight Validation
        $zeroByteFile = tempnam(sys_get_temp_dir(), 'vgt_zero_');
        file_put_contents($zeroByteFile, '');
        $caughtZeroSize = false;
        try {
            FileGuard::validateUploadFile(['name' => 'zero.txt', 'tmp_name' => $zeroByteFile]);
        } catch (ValidationException $e) {
            $caughtZeroSize = str_contains($e->getMessage(), 'Size boundary violation');
        }
        self::assertTrue($caughtZeroSize, 'Pattern 1.5.B: 0-byte file must throw ValidationException');

        $caughtMaxSize = false;
        try {
            FileGuard::validateUploadFile(['name' => 'large.txt', 'tmp_name' => $tempFile], 10);
        } catch (ValidationException $e) {
            $caughtMaxSize = str_contains($e->getMessage(), 'Size boundary violation');
        }
        self::assertTrue($caughtMaxSize, 'Pattern 1.5.B: Exceeding maxBytes must throw ValidationException');

        // 4. VGT PATTERN 1.5.D — MIME Cross-Check (Anti-Polyglot)
        $fakeJpg = tempnam(sys_get_temp_dir(), 'vgt_fake_jpg_');
        file_put_contents($fakeJpg, 'THIS IS NOT A REAL JPEG IMAGE FILE');
        $caughtPolyglot = false;
        try {
            FileGuard::assertImageMimeTypeIntegrity($fakeJpg, 'image/jpeg');
        } catch (SecurityException $e) {
            $caughtPolyglot = str_contains($e->getMessage(), 'Polyglot vector blocked');
        }
        self::assertTrue($caughtPolyglot, 'Pattern 1.5.D: Mismatched MIME/type must throw SecurityException');

        // 5. VGT PATTERN 1.5.E — Path Jail Enforcer
        $validJail = FileGuard::assertWithinJail(__DIR__, 'TestCase.php');
        self::assertTrue(file_exists($validJail), 'Pattern 1.5.E: Valid path within jail must succeed');

        $caughtEscapedJail = false;
        try {
            FileGuard::assertWithinJail(__DIR__, '../../../../escaped.txt');
        } catch (SecurityException $e) {
            $caughtEscapedJail = str_contains($e->getMessage(), 'Path escaped jail');
        }
        self::assertTrue($caughtEscapedJail, 'Pattern 1.5.E: Escaping base directory must throw SecurityException');

        // 6. SVG Threat Detection
        $maliciousSvg1 = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script></svg>';
        self::assertTrue(FileGuard::containsSvgThreats($maliciousSvg1), 'SVG with <script> tag must be detected as threat');

        $maliciousSvg2 = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>';
        self::assertTrue(FileGuard::containsSvgThreats($maliciousSvg2), 'SVG with onload handler must be detected as threat');

        $maliciousSvg3 = '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg>&xxe;</svg>';
        self::assertTrue(FileGuard::containsSvgThreats($maliciousSvg3), 'SVG with XXE entity must be detected as threat');

        $cleanSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="red"/></svg>';
        self::assertFalse(FileGuard::containsSvgThreats($cleanSvg), 'Clean SVG must not be flagged');

        $caughtImageMimeMismatch = false;
        try {
            FileGuard::validateUploadFile(['name' => 'forged.jpg', 'tmp_name' => $fakeJpg]);
        } catch (SecurityException $e) {
            $caughtImageMimeMismatch = str_contains($e->getMessage(), 'MIME validation failed');
        }
        self::assertTrue($caughtImageMimeMismatch, 'Image extensions must match the server-detected MIME type');

        // 7. Forwarded HTTPS metadata is trusted only from configured proxies.
        $_SERVER['HTTPS'] = '';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.25';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        self::assertFalse(HeaderPolicyService::isHttps(), 'Untrusted clients must not forge HTTPS through X-Forwarded-Proto');
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        // 8. Logger Secret Redaction
        $dirtyContext = [
            'username'     => 'admin_user',
            'user_pass'    => 'my_plaintext_password',
            'api_token'    => 'tok_live_9832479238472',
            'master_key'   => '0123456789abcdef',
            'authorization'=> 'Bearer eyJhbGciOi...',
            'safe_metric'  => 42,
        ];
        $cleanContext = Logger::redact($dirtyContext);

        self::assertEquals('[REDACTED]', $cleanContext['user_pass'], 'Password must be redacted');
        self::assertEquals('[REDACTED]', $cleanContext['api_token'], 'Token must be redacted');
        self::assertEquals('[REDACTED]', $cleanContext['master_key'], 'Master key must be redacted');
        self::assertEquals('[REDACTED]', $cleanContext['authorization'], 'Auth header must be redacted');
        self::assertEquals('admin_user', $cleanContext['username'], 'Safe username must be preserved');
        self::assertEquals(42, $cleanContext['safe_metric'], 'Safe metric must be preserved');

        // 9. Real Security Diagnostics & Subsystem Probes
        $cerberusProbe = \Astraea\Diagnostics\SecurityProbeManager::probeCerberus();
        self::assertTrue(isset($cerberusProbe['status'], $cerberusProbe['label'], $cerberusProbe['details']));

        $zeusProbe = \Astraea\Diagnostics\SecurityProbeManager::probeZeusCrypto();
        self::assertEquals(\Astraea\Diagnostics\HealthStatus::HEALTHY, $zeusProbe['status'], 'Live crypto round-trip must be HEALTHY');

        $aegisProbe = \Astraea\Diagnostics\SecurityProbeManager::probeAegis();
        self::assertTrue(isset($aegisProbe['status']));

        $fileGuardProbe = \Astraea\Diagnostics\SecurityProbeManager::probeFileGuard();
        self::assertTrue(isset($fileGuardProbe['status']));

        $vaultProbe = \Astraea\Diagnostics\SecurityProbeManager::probeVault();
        self::assertTrue(isset($vaultProbe['status'], $vaultProbe['label'], $vaultProbe['details']));

        $dbProbe = \Astraea\Diagnostics\SecurityProbeManager::probeDatabase();
        self::assertTrue(isset($dbProbe['status']));

        self::assertTrue(AtomicCounter::consume('test:security:fixed-window', 2, 3600));
        self::assertTrue(AtomicCounter::consume('test:security:fixed-window', 2, 3600));
        self::assertFalse(AtomicCounter::consume('test:security:fixed-window', 2, 3600), 'Atomic counter must reject consumption beyond its hard boundary');

        $manifestProbe = \Astraea\Diagnostics\SecurityProbeManager::probeManifestIntegrity();
        self::assertEquals(\Astraea\Diagnostics\HealthStatus::HEALTHY, $manifestProbe['status'], 'Manifest integrity must be verified');
        self::assertEquals('Verified', $manifestProbe['label']);

        $overall = \Astraea\Diagnostics\SecurityProbeManager::getOverallStatus();
        self::assertTrue($overall instanceof \Astraea\Diagnostics\HealthStatus, 'Overall status must be HealthStatus instance');

        // 10. Upstream Core Update Overwrite Shield
        $emptyTransient = new \stdClass();
        $filteredTransient = \Astraea\Performance\LegacyPruner::filterUpdateCoreTransient($emptyTransient);
        self::assertEquals([], $filteredTransient->updates, 'Updates array must be strictly empty to block upstream core overwrites');
        self::assertTrue(isset($filteredTransient->last_checked), 'last_checked timestamp must be populated');

        // Cleanup temporary files
        @unlink($tempFile);
        @unlink($zeroByteFile);
        @unlink($fakeJpg);

        echo "  -> SecurityTest completed.\n";
    }
}
