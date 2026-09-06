<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Tests;

use Astraea\Auth\SessionManager;
use Astraea\Auth\StepUpAuthService;
use Astraea\Security\SecurityEventManager;
use Astraea\Diagnostics\SecurityProbeManager;
use Astraea\Diagnostics\HealthStatus;

require_once __DIR__ . '/TestCase.php';

final class SessionTest extends TestCase {

    public static function run(): void {
        echo "Running SessionTest...\n";

        // 1. User Agent Classification
        // 1a. Windows 11 Chrome
        $uaWinChrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $resWinChrome = SessionManager::parseUserAgent($uaWinChrome);
        self::assertTrue(str_contains($resWinChrome['os'], 'Windows'), 'UA must detect Windows');
        self::assertTrue(str_contains($resWinChrome['browser'], 'Chrome'), 'UA must detect Chrome');

        // 1b. macOS Safari
        $uaMacSafari = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';
        $resMacSafari = SessionManager::parseUserAgent($uaMacSafari);
        self::assertEquals('macOS', $resMacSafari['os'], 'UA must detect macOS');
        self::assertTrue(str_contains($resMacSafari['browser'], 'Safari'), 'UA must detect Safari');

        // 1c. Linux Firefox
        $uaLinuxFF = 'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0';
        $resLinuxFF = SessionManager::parseUserAgent($uaLinuxFF);
        self::assertEquals('Linux', $resLinuxFF['os'], 'UA must detect Linux');
        self::assertTrue(str_contains($resLinuxFF['browser'], 'Firefox'), 'UA must detect Firefox');

        // 1d. iOS Mobile Safari
        $uaIos = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $resIos = SessionManager::parseUserAgent($uaIos);
        self::assertEquals('iOS', $resIos['os'], 'UA must detect iOS');
        self::assertTrue(str_contains($resIos['browser'], 'Mobile Safari'), 'UA must detect Mobile Safari');

        // 1e. Android Chrome
        $uaAndroid = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36';
        $resAndroid = SessionManager::parseUserAgent($uaAndroid);
        self::assertEquals('Android', $resAndroid['os'], 'UA must detect Android');
        self::assertTrue(str_contains($resAndroid['browser'], 'Chrome Mobile'), 'UA must detect Chrome Mobile');

        // 1f. CLI tool
        $uaCurl = 'curl/8.4.0';
        $resCurl = SessionManager::parseUserAgent($uaCurl);
        self::assertEquals('CLI Client', $resCurl['browser'], 'UA must detect cURL as CLI');

        // 1g. Empty UA
        $resEmpty = SessionManager::parseUserAgent('');
        self::assertEquals('Unknown OS', $resEmpty['os'], 'Empty UA should return Unknown OS');
        self::assertEquals('Unknown Browser', $resEmpty['browser'], 'Empty UA should return Unknown Browser');

        // 2. IP Masking (GDPR / ISO Privacy Compliance)
        // 2a. IPv4
        $maskedIpv4 = SessionManager::maskIp('192.168.1.145');
        self::assertEquals('192.168.1.***', $maskedIpv4, 'IPv4 must mask the last octet');

        // 2b. IPv6
        $maskedIpv6 = SessionManager::maskIp('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        self::assertEquals('2001:0db8:****', $maskedIpv6, 'IPv6 must mask trailing groups');

        // 2c. Invalid IP
        $maskedInvalid = SessionManager::maskIp('invalid.not.an.ip');
        self::assertEquals('0.0.0.0', $maskedInvalid, 'Invalid IP must return 0.0.0.0');

        // 3. StepUpAuthService
        $testUserId = 42;
        StepUpAuthService::clearStepUp($testUserId);
        self::assertFalse(StepUpAuthService::isStepUpActive($testUserId), 'Step-up must initially be inactive');

        StepUpAuthService::recordStepUp($testUserId);
        self::assertTrue(StepUpAuthService::isStepUpActive($testUserId), 'Step-up must be active after recordStepUp');

        StepUpAuthService::clearStepUp($testUserId);
        self::assertFalse(StepUpAuthService::isStepUpActive($testUserId), 'Step-up must be inactive after clearStepUp');

        // 4. SecurityEventManager
        SecurityEventManager::clearEvents();

        // Record events with sensitive tokens to test scrubbing
        SecurityEventManager::recordEvent(
            SecurityEventManager::SEVERITY_INFO,
            'Zeus',
            'test_action',
            'Operation with token=supersecret_token_12345 and password=my_secret_pass'
        );

        $events = SecurityEventManager::getRecentEvents(10);
        self::assertTrue(count($events) >= 1, 'At least 1 event should be retrieved');
        $firstEvent = $events[0];
        self::assertEquals('INFO', $firstEvent['severity'], 'Severity must match INFO');
        self::assertEquals('Zeus', $firstEvent['subsystem'], 'Subsystem must match Zeus');
        self::assertFalse(str_contains($firstEvent['details'], 'supersecret_token_12345'), 'Token must be redacted');
        self::assertFalse(str_contains($firstEvent['details'], 'my_secret_pass'), 'Password must be redacted');
        self::assertTrue(str_contains($firstEvent['details'], '[REDACTED]'), 'Details must contain [REDACTED]');

        // Severity filter
        SecurityEventManager::recordEvent(SecurityEventManager::SEVERITY_WARNING, 'FileGuard', 'blocked_upload', 'Upload blocked');
        $warningsOnly = SecurityEventManager::getRecentEvents(10, 'WARNING');
        self::assertTrue(count($warningsOnly) >= 1, 'Warning event must be returned');
        self::assertEquals('WARNING', $warningsOnly[0]['severity'], 'Filtered events must have WARNING severity');

        // 5. Deep Diagnostics Probes
        $authProbe = SecurityProbeManager::probeAuthentication();
        self::assertTrue(isset($authProbe['status']), 'probeAuthentication must return status');
        self::assertTrue(isset($authProbe['active_sessions']), 'probeAuthentication must return active_sessions');

        $vaultDeep = SecurityProbeManager::probeVaultDeep();
        self::assertTrue(isset($vaultDeep['status']), 'probeVaultDeep must return status');
        self::assertTrue(isset($vaultDeep['storage_writable']), 'probeVaultDeep must return storage_writable');
        self::assertTrue(isset($vaultDeep['resilience_rating']), 'probeVaultDeep must return resilience_rating');

        $posture = SecurityProbeManager::getOverallPostureSummary();
        self::assertTrue(isset($posture['overall']), 'getOverallPostureSummary must return overall');
        self::assertTrue(isset($posture['verified_count']), 'getOverallPostureSummary must return verified_count');
        self::assertTrue($posture['verified_count'] >= 0, 'verified_count must be >= 0');

        $morpheus = SecurityProbeManager::probeMorpheus();
        self::assertTrue(isset($morpheus['status']), 'probeMorpheus must return status');

        // 6. Test Multi-Tab Security HUD Renderers
        $_GET['tab'] = 'overview';
        ob_start();
        \Astraea\AdminUI\Dashboard\ControlCenter::renderDedicatedSecurityPage();
        $overviewHtml = ob_get_clean();
        self::assertTrue(str_contains($overviewHtml, 'Cerberus L0 Request Firewall'), 'Overview tab must render Cerberus');
        self::assertTrue(str_contains($overviewHtml, 'Morpheus RASP Sandbox'), 'Overview tab must render Morpheus');

        $_GET['tab'] = 'integrity';
        ob_start();
        \Astraea\AdminUI\Dashboard\ControlCenter::renderDedicatedSecurityPage();
        $integrityHtml = ob_get_clean();
        self::assertTrue(str_contains($integrityHtml, 'Local SHA-256 File Integrity Manifest'), 'Integrity tab must render file manifest');

        $_GET['tab'] = 'sessions';
        ob_start();
        \Astraea\AdminUI\Dashboard\ControlCenter::renderDedicatedSecurityPage();
        $sessionsHtml = ob_get_clean();
        self::assertTrue(str_contains($sessionsHtml, 'Active Authorized Sessions'), 'Sessions tab must render session table');

        $_GET['tab'] = 'vault';
        ob_start();
        \Astraea\AdminUI\Dashboard\ControlCenter::renderDedicatedSecurityPage();
        $vaultHtml = ob_get_clean();
        self::assertTrue(str_contains($vaultHtml, 'Astraea Vault Binary'), 'Vault tab must render AVB containers');

        $_GET['tab'] = 'events';
        ob_start();
        \Astraea\AdminUI\Dashboard\ControlCenter::renderDedicatedSecurityPage();
        $eventsHtml = ob_get_clean();
        self::assertTrue(str_contains($eventsHtml, 'Centralized Security Audit Trail'), 'Events tab must render audit table');

        unset($_GET['tab']);

        // 7. Session Revocation (RT-01)
        $sessionUserId = 999;
        $tokenA = 'session_token_alpha_1234567890abcdef';
        $verifierA = hash('sha256', $tokenA);
        $tokenB = 'session_token_beta_0987654321fedcba';
        $verifierB = hash('sha256', $tokenB);

        $mockSessions = [
            $verifierA => [
                'expiration' => time() + 3600,
                'ip'         => '192.168.1.100',
                'ua'         => 'TestClientA/1.0',
                'login'      => time() - 600,
            ],
            $verifierB => [
                'expiration' => time() + 3600,
                'ip'         => '10.0.0.50',
                'ua'         => 'TestClientB/1.0',
                'login'      => time() - 300,
            ],
        ];
        update_user_meta($sessionUserId, 'session_tokens', $mockSessions);

        // Verify both sessions exist
        $activeSessions = SessionManager::getUserSessions($sessionUserId);
        self::assertEquals(2, count($activeSessions), 'Should report 2 active sessions');

        // Revoke session A by verifier
        $revokedA = SessionManager::revokeSession($sessionUserId, $verifierA);
        self::assertTrue($revokedA, 'revokeSession must return true for existing verifier');

        // Verify session A is removed and session B remains
        $remaining = SessionManager::getUserSessions($sessionUserId);
        self::assertEquals(1, count($remaining), 'Only 1 session should remain');
        self::assertEquals($verifierB, $remaining[0]['verifier'], 'Remaining session must be verifier B');

        // Revoking non-existent verifier returns false
        $revokedNonExistent = SessionManager::revokeSession($sessionUserId, 'non_existent_verifier_hash');
        self::assertFalse($revokedNonExistent, 'revokeSession must return false for non-existent verifier');

        self::assertFalse(SessionManager::revokeSession($sessionUserId, str_repeat('z', 64)), 'Verifier must be hexadecimal');

        // Persistence failures must never be reported as successful revocations.
        update_user_meta($sessionUserId, 'session_tokens', $mockSessions);
        $GLOBALS['_astraea_test_fail_update_user_meta'] = true;
        self::assertFalse(SessionManager::revokeSession($sessionUserId, $verifierA), 'Failed session-token update must fail closed');
        unset($GLOBALS['_astraea_test_fail_update_user_meta']);
        self::assertEquals(2, count(SessionManager::getUserSessions($sessionUserId)), 'Failed update must preserve both sessions');

        // Bulk revocation must preserve exactly the current raw cookie token.
        $currentVerifier = hash('sha256', wp_get_session_token());
        update_user_meta($sessionUserId, 'session_tokens', [
            $currentVerifier => $mockSessions[$verifierA],
            $verifierB => $mockSessions[$verifierB],
        ]);
        self::assertTrue(SessionManager::revokeOtherSessions($sessionUserId), 'Bulk revocation must succeed with a valid current session');
        $bulkRemaining = SessionManager::getUserSessions($sessionUserId);
        self::assertEquals(1, count($bulkRemaining), 'Bulk revocation must leave exactly one session');
        self::assertEquals($currentVerifier, $bulkRemaining[0]['verifier'], 'Bulk revocation must preserve the current session');

        // Clean up
        delete_user_meta($sessionUserId, 'session_tokens');

        echo "  -> SessionTest completed.\n";
    }
}
