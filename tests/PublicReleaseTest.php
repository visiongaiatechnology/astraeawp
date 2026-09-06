<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Tests;

use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDependencyGraph;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Modules\ModuleInterface;
use Astraea\Modules\BaseModule;
use Astraea\Modules\ModuleRegistry;
use Astraea\Modules\ModuleBootstrap;
use Astraea\Update\ReleaseChannel;
use Astraea\Update\ReleaseManifest;
use Astraea\Update\UpdateVerifier;
use Astraea\Media\SvgSanitizer;
use Astraea\Redirects\RedirectEngine;
use Astraea\Redirects\RedirectRule;
use Astraea\Diagnostics\DiagnosticsBundle;
use Astraea\Crypto\Keyring;
use Astraea\Crypto\CryptoService;
use Astraea\Crypto\KeyContext;
use Astraea\Recovery\BootFailureDetector;
use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;

/**
 * Automated Test Suite for AstraeaOS WP Public Release Subsystems.
 */
final class PublicReleaseTest extends TestCase {

    public static function run(): void {
        echo "Running PublicReleaseTest...\n";

        self::testModuleDependencyGraphTopologicalSort();
        self::testModuleDependencyGraphCycleDetection();
        self::testModuleDependencyGraphMissingDependencyFailClosed();
        self::testModuleHealthProbes();
        self::testUpdateManifestCanonicalPayload();
        self::testUpdateSignatureVerification();
        self::testUpdateDowngradePrevention();
        self::testSvgSanitizerNeutralizesThreats();
        self::testRedirectLoopAndChainDetection();
        self::testRedirectReDoSProtection();
        self::testFormsSubmissionCryptoDomain();
        self::testDiagnosticsSecretRedaction();
        self::testBootFailureDetectorThreshold();

        echo "  -> PublicReleaseTest completed.\n";
    }

    private static function testModuleDependencyGraphTopologicalSort(): void {
        $modA = self::createMockModule('mod_a', BootPhase::PHASE_E, ['mod_b']);
        $modB = self::createMockModule('mod_b', BootPhase::PHASE_E, ['mod_c']);
        $modC = self::createMockModule('mod_c', BootPhase::PHASE_E, []);

        $sorted = ModuleDependencyGraph::resolve(['mod_a' => $modA, 'mod_b' => $modB, 'mod_c' => $modC]);
        $ids = array_map(static fn(ModuleInterface $m) => $m->descriptor()->id, $sorted);

        // Topological order must resolve mod_c before mod_b, and mod_b before mod_a
        self::assertEquals(['mod_c', 'mod_b', 'mod_a'], $ids, 'DAG must resolve dependencies in strict topological order');
    }

    private static function testModuleDependencyGraphCycleDetection(): void {
        $modX = self::createMockModule('mod_x', BootPhase::PHASE_E, ['mod_y']);
        $modY = self::createMockModule('mod_y', BootPhase::PHASE_E, ['mod_x']);

        $caught = false;
        try {
            ModuleDependencyGraph::resolve(['mod_x' => $modX, 'mod_y' => $modY]);
        } catch (SecurityException $e) {
            $caught = true;
            self::assertStringContains('Cyclic module dependency', $e->getMessage());
        }

        self::assertTrue($caught, 'ModuleDependencyGraph must fail-closed and throw SecurityException on cyclic dependencies');
    }

    private static function testModuleDependencyGraphMissingDependencyFailClosed(): void {
        $modOrphan = self::createMockModule('mod_orphan', BootPhase::PHASE_E, ['non_existent_core']);

        $caught = false;
        try {
            ModuleDependencyGraph::resolve(['mod_orphan' => $modOrphan]);
        } catch (SecurityException $e) {
            $caught = true;
            self::assertStringContains('Missing required dependency', $e->getMessage());
        }

        self::assertTrue($caught, 'ModuleDependencyGraph must fail-closed on missing dependencies');
    }

    private static function testModuleHealthProbes(): void {
        $healthy = ModuleHealth::healthy('Operational', 'All systems operational', ['stat' => 1], 10.0);
        self::assertEquals(ModuleHealth::HEALTHY, $healthy->status);
        self::assertTrue($healthy->isHealthy());
        self::assertEquals(10.0, $healthy->latencyMs);

        $degraded = ModuleHealth::degraded('High load');
        self::assertEquals(ModuleHealth::DEGRADED, $degraded->status);
        self::assertFalse($degraded->isHealthy());

        $critical = ModuleHealth::critical('Database unreachable');
        self::assertEquals(ModuleHealth::CRITICAL, $critical->status);
        self::assertFalse($critical->isHealthy());
    }

    private static function testUpdateManifestCanonicalPayload(): void {
        $manifest = new ReleaseManifest(
            product: 'astraeaos-wp',
            version: '0.7.0',
            channel: ReleaseChannel::STABLE,
            minimumPhp: '8.3.0',
            minimumDatabase: '8.0.0',
            packageUrl: 'https://updates.astraea.internal/0.7.0.zip',
            sha256: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            releaseTimestamp: 1772800000,
            schemaVersion: '1.0.0',
            migrationVersion: '1.0.0',
            signature: 'dummy_sig',
            releaseNotesUrl: 'https://updates.astraea.internal/notes/0.7.0'
        );

        $canonical = $manifest->canonicalPayload();
        self::assertStringStartsWith('ASTRAEA-UPDATE-MANIFEST-V1', $canonical);
        self::assertStringContains('version=0.7.0', $canonical);
        self::assertStringContains('channel=stable', $canonical);
        self::assertStringContains('sha256=e3b0c442', $canonical);
    }

    private static function testUpdateSignatureVerification(): void {
        if (!extension_loaded('sodium')) {
            return;
        }

        $kp = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($kp);
        $publicKey = sodium_crypto_sign_publickey($kp);
        $publicKeyHex = bin2hex($publicKey);
        $oldEnv = getenv('ASTRAEA_RELEASE_PUBLIC_KEY');
        putenv('ASTRAEA_RELEASE_PUBLIC_KEY=' . $publicKeyHex);

        try {
            $manifest = new ReleaseManifest(
                product: 'astraeaos-wp',
                version: '0.7.0',
                channel: ReleaseChannel::STABLE,
                minimumPhp: '8.3.0',
                minimumDatabase: '8.0.0',
                packageUrl: 'https://updates.astraea.internal/0.7.0.zip',
                sha256: 'a1b2c3d4e5f607182930485761829304a1b2c3d4e5f607182930485761829304',
                releaseTimestamp: 1772800000,
                schemaVersion: '1.0.0',
                migrationVersion: '1.0.0'
            );

            // 1. Unsigned manifest status
            self::assertEquals(
                \Astraea\Update\AuthenticityStatus::UNSIGNED,
                UpdateVerifier::verifySignature($manifest),
                'Unsigned manifest must return UNSIGNED authenticity status'
            );

            // 2. Sign canonical payload
            $payload = $manifest->canonicalPayload();
            $sig = sodium_crypto_sign_detached($payload, $secretKey);
            $encodedSig = base64_encode($sig);

            $signedManifest = new ReleaseManifest(
                product: $manifest->product,
                version: $manifest->version,
                channel: $manifest->channel,
                minimumPhp: $manifest->minimumPhp,
                minimumDatabase: $manifest->minimumDatabase,
                packageUrl: $manifest->packageUrl,
                sha256: $manifest->sha256,
                releaseTimestamp: $manifest->releaseTimestamp,
                schemaVersion: $manifest->schemaVersion,
                migrationVersion: $manifest->migrationVersion,
                signature: $encodedSig
            );

            self::assertEquals(
                \Astraea\Update\AuthenticityStatus::SIGNED_VERIFIED,
                UpdateVerifier::verifySignature($signedManifest),
                'Valid Ed25519 signature must return SIGNED_VERIFIED'
            );

            // 3. Tamper payload -> must fail
            $tamperedManifest = new ReleaseManifest(
                product: $manifest->product,
                version: '0.7.1', // Tampered version!
                channel: $manifest->channel,
                minimumPhp: $manifest->minimumPhp,
                minimumDatabase: $manifest->minimumDatabase,
                packageUrl: $manifest->packageUrl,
                sha256: $manifest->sha256,
                releaseTimestamp: $manifest->releaseTimestamp,
                schemaVersion: $manifest->schemaVersion,
                migrationVersion: $manifest->migrationVersion,
                signature: $encodedSig
            );
            self::assertEquals(
                \Astraea\Update\AuthenticityStatus::SIGNED_INVALID,
                UpdateVerifier::verifySignature($tamperedManifest),
                'Tampered manifest must return SIGNED_INVALID'
            );
        } finally {
            if ($oldEnv !== false) {
                putenv('ASTRAEA_RELEASE_PUBLIC_KEY=' . $oldEnv);
            } else {
                putenv('ASTRAEA_RELEASE_PUBLIC_KEY');
            }
        }
    }

    private static function testUpdateDowngradePrevention(): void {
        $olderManifest = new ReleaseManifest(
            product: 'astraeaos-wp',
            version: '0.1.0', // Older than active version
            channel: ReleaseChannel::STABLE,
            minimumPhp: '8.3.0',
            minimumDatabase: '8.0.0',
            packageUrl: 'https://updates.astraea.internal/0.1.0.zip',
            sha256: 'a1b2c3d4e5f607182930485761829304a1b2c3d4e5f607182930485761829304',
            releaseTimestamp: 1772800000,
            schemaVersion: '1.0.0',
            migrationVersion: '1.0.0'
        );

        $caught = false;
        try {
            UpdateVerifier::verify($olderManifest, false);
        } catch (SecurityException $e) {
            $caught = true;
            self::assertStringContains('Downgrade attack rejected', $e->getMessage());
        }

        self::assertTrue($caught, 'UpdateVerifier must reject downgrade attacks');
    }

    private static function testSvgSanitizerNeutralizesThreats(): void {
        $maliciousSvg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
  <script type="text/javascript">alert('XSS');</script>
  <circle cx="50" cy="50" r="40" stroke="green" stroke-width="4" fill="yellow" onclick="stealCookies()" />
  <a href="javascript:alert(1)"><text x="10" y="20">Click</text></a>
</svg>
SVG;

        $caught = false;
        try {
            SvgSanitizer::sanitize($maliciousSvg);
        } catch (SecurityException $e) {
            $caught = true;
            self::assertStringContains('malicious active vectors', $e->getMessage());
        }
        self::assertTrue($caught, 'SvgSanitizer must throw SecurityException when malicious vectors are detected');

        $cleanSvg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
  <circle cx="50" cy="50" r="40" stroke="green" stroke-width="4" fill="yellow" />
</svg>
SVG;
        $sanitized = SvgSanitizer::sanitize($cleanSvg);
        self::assertStringContains('<circle', $sanitized, 'SvgSanitizer must preserve clean visual SVG elements');
    }

    private static function testRedirectLoopAndChainDetection(): void {
        // Direct loop: /alpha -> /alpha
        $loopRule = new RedirectRule('r1', '/alpha', '/alpha', 301, 'exact');
        self::assertTrue(RedirectEngine::detectLoop($loopRule, '/alpha', [$loopRule]), 'Direct self-redirect must be identified as circular');

        // Chain loop: /first -> /second -> /first
        $r1 = new RedirectRule('r1', '/first', '/second', 301, 'exact');
        $r2 = new RedirectRule('r2', '/second', '/first', 301, 'exact');
        self::assertTrue(RedirectEngine::detectLoop($r1, '/first', [$r1, $r2]), 'Two-step circular chain must be identified as circular');

        // Safe linear chain: /start -> /middle -> /end (does not loop)
        $s1 = new RedirectRule('s1', '/start', '/middle', 301, 'exact');
        $s2 = new RedirectRule('s2', '/middle', '/end', 301, 'exact');
        self::assertFalse(RedirectEngine::detectLoop($s1, '/start', [$s1, $s2]), 'Linear non-looping chain must be identified as safe');
    }

    private static function testRedirectReDoSProtection(): void {
        // Overly long regex (>128 chars) must be rejected
        $longPattern = str_repeat('a', 130);
        $caught = false;
        try {
            RedirectEngine::assertRegexSafe($longPattern);
        } catch (SecurityException $e) {
            $caught = true;
            self::assertStringContains('exceeds maximum allowed length', $e->getMessage());
        }
        self::assertTrue($caught, 'RedirectEngine must reject regex patterns exceeding 128 chars to prevent ReDoS');

        // Dangerous nested quantifiers
        $caughtNested = false;
        try {
            RedirectEngine::assertRegexSafe('^(a+)+$');
        } catch (SecurityException $e) {
            $caughtNested = true;
            self::assertStringContains('Dangerous nested quantifier', $e->getMessage());
        }
        self::assertTrue($caughtNested, 'RedirectEngine must reject nested quantifiers with high catastrophic backtracking risk');

        $caughtVerb = false;
        try {
            RedirectEngine::assertRegexSafe('(*NO_AUTO_POSSESS)^z*z*z*z*z*z*y$');
        } catch (SecurityException) {
            $caughtVerb = true;
        }
        self::assertTrue($caughtVerb, 'RedirectEngine must reject PCRE control verbs that bypass input-specific preflight probes');
    }

    private static function testFormsSubmissionCryptoDomain(): void {
        Keyring::init();
        self::assertTrue(Keyring::isInitialized());

        $payload = ['name' => 'Alice', 'email' => 'alice@example.com', 'message' => 'Test message'];
        $json = json_encode($payload);

        $ciphertext = CryptoService::encrypt($json, KeyContext::FORMS_SUBMISSION);
        self::assertStringStartsWith('vgt:a1:', $ciphertext);

        $decrypted = CryptoService::decrypt($ciphertext, KeyContext::FORMS_SUBMISSION);
        self::assertEquals($json, $decrypted, 'Form submission round-trip AEAD encryption must preserve data bytes');

        // Tampered domain must fail closed
        $domainMismatch = false;
        try {
            CryptoService::decrypt($ciphertext, KeyContext::MAIL_TRANSPORT);
        } catch (SecurityException) {
            $domainMismatch = true;
        }
        self::assertTrue($domainMismatch, 'Form ciphertext decrypted under wrong crypto domain must fail closed');
    }

    private static function testDiagnosticsSecretRedaction(): void {
        $rawConfig = [
            'database' => [
                'db_name' => 'wordpress_db',
                'db_user' => 'wp_admin',
                'db_password' => 'SuperSecret12345!',
            ],
            'mail' => [
                'smtp_host' => 'smtp.office365.com',
                'smtp_pass' => 'MyMailPass999',
                'oauth_secret' => 'ClientSecretValue',
            ],
            'keys' => [
                'auth_key' => 'very_secret_auth_key_string',
                'token' => 'bearer_token_xyz',
                'public_info' => 'safe_to_show',
            ]
        ];

        $redacted = DiagnosticsBundle::redactRecursive($rawConfig);

        self::assertEquals('[REDACTED]', $redacted['database']['db_password']);
        self::assertEquals('[REDACTED]', $redacted['mail']['smtp_pass']);
        self::assertEquals('[REDACTED]', $redacted['mail']['oauth_secret']);
        self::assertEquals('[REDACTED]', $redacted['keys']['auth_key']);
        self::assertEquals('[REDACTED]', $redacted['keys']['token']);
        self::assertEquals('safe_to_show', $redacted['keys']['public_info']);
        self::assertEquals('wordpress_db', $redacted['database']['db_name']);
    }

    private static function testBootFailureDetectorThreshold(): void {
        BootFailureDetector::reset();
        self::assertEquals(0, BootFailureDetector::getFailureCount());
        self::assertFalse(BootFailureDetector::isRecoveryRecommended());

        // Simulate 3 boot failures
        BootFailureDetector::trackBootStart();
        BootFailureDetector::disengageForTest();
        BootFailureDetector::trackBootStart();
        BootFailureDetector::disengageForTest();
        BootFailureDetector::trackBootStart();

        self::assertTrue(BootFailureDetector::isRecoveryRecommended(), 'Recovery gate must engage at or above MAX_BOOT_FAILURES');
        self::assertEquals(3, BootFailureDetector::getFailureCount());

        // Reset
        BootFailureDetector::reset();
        self::assertEquals(0, BootFailureDetector::getFailureCount());
        self::assertFalse(BootFailureDetector::isRecoveryRecommended());
    }

    private static function createMockModule(string $id, BootPhase $phase, array $dependencies): ModuleInterface {
        return new class($id, $phase, $dependencies) extends BaseModule {
            public function __construct(
                private readonly string $mId,
                private readonly BootPhase $mPhase,
                private readonly array $mDeps
            ) {}

            public function descriptor(): ModuleDescriptor {
                return new ModuleDescriptor(
                    id: $this->mId,
                    name: ucfirst($this->mId),
                    version: '1.0.0',
                    bootPhase: $this->mPhase,
                    dependencies: $this->mDeps,
                    isToggleable: true
                );
            }

            public function boot(): void {}

            public function probeHealth(): ModuleHealth {
                return ModuleHealth::healthy('Mock OK');
            }
        };
    }
}
