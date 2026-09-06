<?php
declare(strict_types=1);

namespace Astraea\Tests;

use Astraea\Crypto\MasterKeyManager;
use Astraea\GeDefense\GeDefenseKernel;
use VIS_Vault;

require_once __DIR__ . '/TestCase.php';

final class GeDefenseTest extends TestCase {

    public static function run(): void {
        echo "Running GeDefenseTest...\n";

        // 1. GeDefense Kernel Bootstrap
        GeDefenseKernel::boot();
        self::assertTrue(defined('VIS_VERSION'), 'VIS_VERSION constant must be defined');
        self::assertStringContains('astraea', VIS_VERSION, 'VIS_VERSION must identify as Astraea integration');

        // 2. VIS_Vault Integration with Astraea MasterKeyManager
        MasterKeyManager::setMasterKey('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
        $secret = 'GeDefense_API_Secret_Key_Token_Alpha';
        $vaultEncrypted = VIS_Vault::encrypt($secret);

        self::assertStringStartsWith('vgt1:', $vaultEncrypted, 'VIS_Vault output must start with vgt1:');
        $vaultDecrypted = VIS_Vault::decrypt($vaultEncrypted);
        self::assertEquals($secret, $vaultDecrypted, 'VIS_Vault decrypt must restore exact secret');

        // 3. Aegis DPI Real Production Inspection Pipeline
        require_once VIS_PATH . 'includes/modules/aegis/class-vis-aegis.php';
        $aegis = new \VIS_Aegis(['aegis_enabled' => false]);

        // Verify SQL Injection detection via real Aegis kernel
        $sqliPayload = "1' UNION SELECT null, user_login, user_pass FROM wp_users--";
        $sqliResult = $aegis->assess_payload($sqliPayload);
        self::assertEquals('BLOCK', $sqliResult['verdict'], 'Aegis must detect and block SQL injection payload');
        self::assertStringStartsWith('sqli', $sqliResult['vector'], 'Aegis threat vector must identify SQL injection');

        // Verify XSS attack vector detection via real Aegis kernel
        $xssPayload = '<svg/onload=alert(document.cookie)>';
        $xssResult = $aegis->assess_payload($xssPayload);
        self::assertEquals('BLOCK', $xssResult['verdict'], 'Aegis must detect and block XSS attack vector');
        self::assertStringStartsWith('xss', $xssResult['vector'], 'Aegis threat vector must identify XSS');

        // Verify PHP RCE execution detection via real Aegis kernel
        $rcePayload = 'system($_GET["cmd"])';
        $rceResult = $aegis->assess_payload($rcePayload);
        self::assertEquals('BLOCK', $rceResult['verdict'], 'Aegis must detect and block PHP RCE payload');
        self::assertStringStartsWith('rce', $rceResult['vector'], 'Aegis threat vector must identify RCE');

        // Verify Benign payload is allowed without false positives
        $safePayload = 'Hello world, AstraeaOS WP test suite is executing cleanly.';
        $safeResult = $aegis->assess_payload($safePayload);
        self::assertEquals('ALLOW', $safeResult['verdict'], 'Aegis must permit safe, benign payloads');
        self::assertEquals('none', $safeResult['vector'], 'Benign payload must have vector "none"');

        echo "  -> GeDefenseTest completed.\n";
    }
}
