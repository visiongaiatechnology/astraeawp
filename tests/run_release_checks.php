<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$results = [];

function check(string $name, bool $condition, string $detail = ''): void {
    global $passed, $failed, $results;
    if ($condition) { $passed++; } else { $failed++; }
    $results[] = [$condition ? 'PASS' : 'FAIL', $name, $detail];
}
function source(string $path): string {
    $data = file_get_contents($path);
    return is_string($data) ? $data : '';
}
function containsAny(string $haystack, array $needles): bool {
    foreach ($needles as $needle) { if (str_contains($haystack, $needle)) return true; }
    return false;
}

require_once $root . '/astraea-core/Version.php';
check('Distribution version source', \Astraea\Version::VERSION === '0.6.0-alpha');

$versionPhp = source($root . '/wp-includes/version.php');
check('wp-includes version delegates to Astraea Version', str_contains($versionPhp, '\\Astraea\\Version::VERSION'));

// Core cryptography/key lifecycle tests against production classes.
require_once $root . '/astraea-core/Exceptions/AppException.php';
require_once $root . '/astraea-core/Exceptions/ValidationException.php';
require_once $root . '/astraea-core/Exceptions/SecurityException.php';
require_once $root . '/astraea-core/Crypto/CryptoAuthenticationException.php';
require_once $root . '/astraea-core/Crypto/KeyContext.php';
require_once $root . '/astraea-core/Crypto/KeyState.php';
require_once $root . '/astraea-core/Crypto/KeyRecord.php';
require_once $root . '/astraea-core/Crypto/MasterKeyManager.php';
require_once $root . '/astraea-core/Crypto/Keyring.php';
require_once $root . '/astraea-core/Crypto/CryptoService.php';

try {
    $keyringPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'astraea-keyring-test-' . bin2hex(random_bytes(8)) . '.keys';
    putenv('ASTRAEA_KEYRING_FILE=' . $keyringPath);
    \Astraea\Crypto\Keyring::reset();
    $oldKey = random_bytes(32);
    $newKey = random_bytes(32);
    $oldRecord = \Astraea\Crypto\Keyring::registerKey($oldKey, \Astraea\Crypto\KeyState::ACTIVE);
    $oldEnvelope = \Astraea\Crypto\CryptoService::encrypt('rotation-secret', \Astraea\Crypto\KeyContext::SECRETS, 'rotation-aad');
    $newRecord = \Astraea\Crypto\Keyring::rotateKey($newKey);
    $oldAfterRotation = \Astraea\Crypto\Keyring::resolveKey($oldRecord->keyId);
    check('Key rotation demotes previous key to DECRYPT_ONLY', $oldAfterRotation->state === \Astraea\Crypto\KeyState::DECRYPT_ONLY);
    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $perms = fileperms($keyringPath) & 0777;
    check('Key rotation persists historical key outside database', is_file($keyringPath) && ($isWindows ? ($perms === 0666 || $perms === 0600) : $perms === 0600));
    check('Historical ciphertext decrypts after key rotation', \Astraea\Crypto\CryptoService::decrypt($oldEnvelope, \Astraea\Crypto\KeyContext::SECRETS, 'rotation-aad') === 'rotation-secret');
    $newEnvelope = \Astraea\Crypto\CryptoService::encrypt('new-secret', \Astraea\Crypto\KeyContext::SECRETS, 'rotation-aad');
    check('New ciphertext uses new ACTIVE key id', str_contains($newEnvelope, ':' . $newRecord->keyId . ':'));

    // Recreate the keyring exactly as a new request would: active key from
    // MasterKeyManager + historical key from external persistent keyring.
    \Astraea\Crypto\Keyring::reset();
    check('Historical ciphertext survives process/keyring reinitialization', \Astraea\Crypto\CryptoService::decrypt($oldEnvelope, \Astraea\Crypto\KeyContext::SECRETS, 'rotation-aad') === 'rotation-secret');

    $unknownEnvelope = preg_replace('/^(vgt:[^:]+:[^:]+:)[^:]+:/', '$1deadbeef:', $oldEnvelope, 1);
    $unknownRejected = false;
    try {
        if (!is_string($unknownEnvelope)) throw new RuntimeException('Envelope rewrite failed');
        \Astraea\Crypto\CryptoService::decrypt($unknownEnvelope, \Astraea\Crypto\KeyContext::SECRETS, 'rotation-aad');
    } catch (\Astraea\Crypto\CryptoAuthenticationException) {
        $unknownRejected = true;
    }
    check('Unknown key id fails closed without resetting valid keyring state', $unknownRejected);
    check('Historical key remains usable after unknown-key rejection', \Astraea\Crypto\CryptoService::decrypt($oldEnvelope, \Astraea\Crypto\KeyContext::SECRETS, 'rotation-aad') === 'rotation-secret');

    \Astraea\Crypto\Keyring::revokeKey($oldRecord->keyId);
    \Astraea\Crypto\Keyring::reset();
    $revokedRejected = false;
    try {
        \Astraea\Crypto\CryptoService::decrypt($oldEnvelope, \Astraea\Crypto\KeyContext::SECRETS, 'rotation-aad');
    } catch (\Astraea\Exceptions\SecurityException) {
        $revokedRejected = true;
    }
    check('REVOKED key remains blocked after keyring reinitialization', $revokedRejected);
    @unlink($keyringPath);
    putenv('ASTRAEA_KEYRING_FILE');
} catch (Throwable $e) {
    if (isset($keyringPath) && is_string($keyringPath)) @unlink($keyringPath);
    putenv('ASTRAEA_KEYRING_FILE');
    check('Core Keyring rotation tests executable', false, get_class($e));
}

// Password pepper lifecycle uses production PasswordService/Argon2id.
require_once $root . '/astraea-core/Auth/PepperManager.php';
require_once $root . '/astraea-core/Auth/Argon2idPolicy.php';
require_once $root . '/astraea-core/Auth/LegacyHashVerifier.php';
require_once $root . '/astraea-core/Auth/PasswordService.php';
try {
    putenv('ASTRAEA_PASSWORD_PEPPER_PREVIOUS=["old-pepper-for-release-test"]');
    \Astraea\Auth\PepperManager::setPepper('old-pepper-for-release-test');
    $pepperedHash = \Astraea\Auth\PasswordService::hash('  whitespace-is-secret  ');
    \Astraea\Auth\PepperManager::setPepper('new-pepper-for-release-test');
    check('Previous pepper verifies for migration', \Astraea\Auth\PasswordService::verify('  whitespace-is-secret  ', $pepperedHash));
    check('Previous-pepper verification marks hash for rehash', \Astraea\Auth\PasswordService::needsRehash($pepperedHash));
    $newPepperHash = \Astraea\Auth\PasswordService::hash('  whitespace-is-secret  ');
    check('Current pepper hash verifies', \Astraea\Auth\PasswordService::verify('  whitespace-is-secret  ', $newPepperHash));
    check('Password whitespace remains credential-significant', !\Astraea\Auth\PasswordService::verify('whitespace-is-secret', $newPepperHash));
    putenv('ASTRAEA_PASSWORD_PEPPER_PREVIOUS');
    \Astraea\Auth\PepperManager::setPepper(null);
} catch (Throwable $e) {
    check('Password pepper lifecycle tests executable', false, get_class($e));
}

$step = source($root . '/astraea-core/Auth/StepUpAuthService.php');
check('Step-Up bound to WordPress session token', str_contains($step, 'wp_get_session_token') && str_contains($step, 'astraea_step_up_sessions'));
check('Legacy user-global Step-Up timestamp removed', !str_contains($step, 'astraea_step_up_timestamp'));

$guard = source($root . '/astraea-core/Auth/PrivilegedActionGuard.php');
check('Interactive plugin/theme updater guard exists', str_contains($guard, "upgrader_pre_install") && str_contains($guard, 'guardSensitiveAction'));

$vaultAdmin = source($root . '/astraea-core/Vault/src/Admin/AdminActions.php');
check('Vault critical operations require Step-Up', substr_count($vaultAdmin, 'requireStepUp(') >= 7);

$events = source($root . '/astraea-core/Security/SecurityEventManager.php');
check('Synthetic security baseline events absent', !containsAny($events, ['getBaselineBootstrapEvents', 'ignition sequence verified successfully']));

$eventBus = source($root . '/astraea-core/GeDefense/includes/core/class-vis-event-bus.php');
check('GeDefense EventBus bridges real events', str_contains($eventBus, 'astraea_gedefense_security_event'));

$probe = source($root . '/astraea-core/Diagnostics/SecurityProbeManager.php');
check('Passkeys explicitly not implemented', str_contains($probe, "'passkeys_status' => 'NOT IMPLEMENTED'") && !str_contains($probe, '$passkeysAvailable = true'));
check('Morpheus health requires runtime class', str_contains($probe, 'Morpheus_Hypervisor') && str_contains($probe, 'Configured / Not Loaded'));
check('Vault probe uses actual boot/update evidence', str_contains($probe, 'isEarlyRecoveryBooted') && str_contains($probe, 'UpdateGuard::isRegistered'));

$headers = source($root . '/astraea-core/Security/HeaderPolicyService.php');
check('Titan authoritative header policy prevents competing baseline', str_contains($headers, 'isTitanAuthoritative') && str_contains($headers, 'return $headers;'));

$recovery = source($root . '/astraea-core/Vault/src/Recovery/RecoveryManager.php');
check('Network plugin quarantine handled', str_contains($recovery, 'active_sitewide_plugins') && str_contains($recovery, 'update_site_option'));

$wpSettings = source($root . '/wp-settings.php');
check('Removed wp-content Vault fallback', !str_contains($wpSettings, "WP_PLUGIN_DIR . '/astraea-vault"));
check('Phase D remains before MU plugin load', strpos($wpSettings, 'bootPhaseD()') !== false && strpos($wpSettings, 'bootPhaseD()') < strpos($wpSettings, 'wp_get_mu_plugins()'));

check('No duplicate Vault plugin directory', !is_dir($root . '/wp-content/plugins/astraea-vault'));

$vlpBoot = source($root . '/astraea-core/Bootstrap/BootOrchestrator.php');
check('VLP Light kernel is booted by Astraea Phase E', str_contains($vlpBoot, 'VLPLightKernel::boot()'));
$vlpMigration = source($root . '/astraea-core/Database/Migrations/Migration_002_VLPLight.php');
check('VLP Light schema migration exists', str_contains($vlpMigration, 'astraea_vlp_dattrack_events') && str_contains($vlpMigration, 'visitor_day'));
$keyContext = source($root . '/astraea-core/Crypto/KeyContext.php');
check('VLP uses dedicated cryptographic domains', str_contains($keyContext, 'VLP_CONSENT') && str_contains($keyContext, 'VLP_DATTRACK'));
$consent = source($root . '/astraea-core/VLP/Light/Consent/ConsentManager.php');
check('Consent receipt is HttpOnly and HMAC authenticated', str_contains($consent, "'httponly'=>true") && str_contains($consent, 'hash_hmac') && str_contains($consent, 'hash_equals'));
$gate = source($root . '/astraea-core/VLP/Light/Gatekeeper/DomGatekeeper.php');
check('VLP server DOM gate uses WordPress tag processor', str_contains($gate, 'WP_HTML_Tag_Processor') && str_contains($gate, 'data-vlp-blocked'));
$vlpJs = source($root . '/astraea-core/VLP/Light/assets/js/vlp-banner.js');
check('VLP dynamic DOM/network gate is synchronous', str_contains($vlpJs, 'Node.prototype.appendChild') && str_contains($vlpJs, 'XMLHttpRequest.prototype.open') && str_contains($vlpJs, 'navigator.sendBeacon'));
check('VLP client cookie gate exists', str_contains($vlpJs, "Object.getOwnPropertyDescriptor(Document.prototype,'cookie')"));
$dattrack = source($root . '/astraea-core/VLP/Light/Dattrack/DattrackService.php');
check('Dattrack requires statistics consent', str_contains($dattrack, "categoryAllowed('statistics')"));
check('Dattrack does not trust proxy IP headers', !containsAny($dattrack, ['HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP']));
check('Dattrack uses Astraea AEAD and no raw IP persistence field', str_contains($dattrack, 'CryptoService::encrypt') && !str_contains($vlpMigration, 'raw_ip'));
$scanner = source($root . '/astraea-core/VLP/Light/Scanner/ScannerService.php');
check('VLP scanner enforces path jail and file bounds', str_contains($scanner, 'realpath') && str_contains($scanner, 'MAX_FILE_BYTES') && str_contains($scanner, 'isLink'));
$vlpAdmin = source($root . '/astraea-core/VLP/Light/Admin/AdminPage.php');
check('VLP policy/service mutation requires Step-Up', str_contains($vlpAdmin, 'guardSensitiveAction') && substr_count($vlpAdmin, "self::guard(") >= 3);

$runtimeFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/astraea-core', FilesystemIterator::SKIP_DOTS));
foreach ($it as $item) {
    if (!$item->isFile()) continue;
    if (!preg_match('/\.(?:php|js|css)$/i', $item->getFilename())) continue;
    $runtimeFiles[] = $item->getPathname();
}
$externalUi = false;
$dangerousJs = false;
foreach ($runtimeFiles as $file) {
    $data = source($file);
    if (str_contains($file, '/AdminUI/') || str_contains($file, '/Vault/') || str_contains($file, '/VLP/') || str_contains($file, '/Mail/')) {
        if (preg_match('/(?:cdn\.|jsdelivr|unpkg|fonts\.googleapis|fonts\.gstatic)/i', $data)) $externalUi = true;
    }
    if (str_ends_with($file, '.js') && (str_contains($file, '/AdminUI/') || str_contains($file, '/Vault/') || str_contains($file, '/VLP/') || str_contains($file, '/Mail/'))) {
        if (preg_match('/\b(?:innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\s*\()/i', $data)) $dangerousJs = true;
    }
}
check('AdminUI/Vault/VLP has no CDN dependency', !$externalUi);
check('AdminUI/Vault/VLP JS avoids dangerous HTML/eval sinks', !$dangerousJs);


// Astraea Mail Gateway static and cryptographic storage checks.
$mailBoot = source($root . '/astraea-core/Bootstrap/BootOrchestrator.php');
check('Astraea Mail Gateway boots in Phase E', str_contains($mailBoot, 'MailKernel::boot()'));
$mailKeyContext = source($root . '/astraea-core/Crypto/KeyContext.php');
check('Mail Gateway has dedicated crypto domain', str_contains($mailKeyContext, 'MAIL_TRANSPORT'));
$mailTransport = source($root . '/astraea-core/Mail/Transport/TransportManager.php');
check('Mail transport disables opportunistic TLS downgrade', str_contains($mailTransport, 'SMTPAutoTLS = false'));
check('Mail transport fails closed on encrypted config failure', str_contains($mailTransport, 'hasLoadFailure()') && str_contains($mailTransport, 'return false;'));
$mailTls = source($root . '/astraea-core/Mail/Transport/TlsPolicy.php');
check('SMTP TLS verifies certificate and peer name', str_contains($mailTls, "'verify_peer' => true") && str_contains($mailTls, "'verify_peer_name' => true") && str_contains($mailTls, "'allow_self_signed' => false"));
$strictSmtp = source($root . '/astraea-core/Mail/Transport/StrictSMTP.php');
check('STARTTLS is pinned to TLS 1.2/1.3 methods', str_contains($strictSmtp, 'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') && str_contains($strictSmtp, 'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') && !str_contains($strictSmtp, 'TLSv1_1'));
$mailJournal = source($root . '/astraea-core/Mail/Transport/DeliveryJournal.php');
check('Mail delivery journal excludes message contents and recipient addresses', !containsAny($mailJournal, ['subject', 'message_body', 'recipient_address', 'attachment_path']));
$mailAdmin = source($root . '/astraea-core/Mail/Admin/AdminPage.php');
check('SMTP configuration and diagnostics require session Step-Up', str_contains($mailAdmin, 'guardSensitiveAction') && substr_count($mailAdmin, 'self::guard(') >= 4);
$mailProvider = source($root . '/astraea-core/Mail/ProviderRegistry.php');
check('SMTP provider presets plus Custom exist', containsAny($mailProvider, ["'gmail'", "'microsoft365'"]) && str_contains($mailProvider, "'custom'"));
check('Microsoft 365 preset uses XOAUTH2 modern authentication', str_contains($mailProvider, "'microsoft365'") && str_contains($mailProvider, "'auth_type' => 'xoauth2'") && str_contains($mailProvider, 'login.microsoftonline.com'));
$mailOauthService = source($root . '/astraea-core/Mail/Transport/OAuthTokenService.php');
check('OAuth refresh flow is native and redirect-disabled', str_contains($mailOauthService, 'wp_remote_post') && str_contains($mailOauthService, "'redirection' => 0") && str_contains($mailOauthService, "'reject_unsafe_urls' => true"));
check('OAuth access-token cache uses encrypted Astraea record store', str_contains($mailOauthService, 'EncryptedRecordStore::saveObject') && str_contains($mailOauthService, 'OAUTH_CACHE_AAD'));
check('Mail transport wires PHPMailer XOAUTH2 provider', str_contains($mailTransport, "AuthType = 'XOAUTH2'") && str_contains($mailTransport, 'new OAuthProvider($config)'));
$mailEndpoint = source($root . '/astraea-core/Mail/Security/EndpointPolicy.php');
check('SMTP endpoint policy blocks metadata/private targets by default', str_contains($mailEndpoint, '169.254.169.254') && str_contains($mailEndpoint, 'FILTER_FLAG_NO_PRIV_RANGE'));

require_once $root . '/astraea-core/Mail/Settings.php';
require_once $root . '/astraea-core/Mail/ProviderRegistry.php';
require_once $root . '/astraea-core/Mail/Security/EndpointPolicy.php';
try {
    $metadataRejected = false;
    try { \Astraea\Mail\Security\EndpointPolicy::assertAllowed('169.254.169.254', true); }
    catch (\Astraea\Exceptions\SecurityException) { $metadataRejected = true; }
    check('SMTP metadata endpoint rejected even in private-relay mode', $metadataRejected);
    $privateRejected = false;
    try { \Astraea\Mail\Security\EndpointPolicy::assertAllowed('127.0.0.1', false); }
    catch (\Astraea\Exceptions\SecurityException) { $privateRejected = true; }
    check('Private SMTP target requires explicit authorization', $privateRejected);
    $publicPlainRejected = false;
    try { \Astraea\Mail\Security\EndpointPolicy::assertTransportAllowed('1.1.1.1', true, 'none'); }
    catch (\Astraea\Exceptions\SecurityException) { $publicPlainRejected = true; }
    check('Plaintext SMTP remains forbidden for public targets even with private-relay toggle', $publicPlainRejected);
    check('IPv6 SMTP literal normalizes without URL ambiguity', \Astraea\Mail\Security\EndpointPolicy::normalizeHost('[::1]') === '::1');
} catch (Throwable $e) {
    check('SMTP endpoint policy tests executable', false, get_class($e));
}

// Production encrypted config round-trip with backslash-significant credentials.
if (!function_exists('sanitize_key')) { function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$key)) ?? ''; } }
if (!function_exists('sanitize_email')) { function sanitize_email($email) { return filter_var((string)$email, FILTER_SANITIZE_EMAIL) ?: ''; } }
if (!function_exists('is_email')) { function is_email($email) { return filter_var((string)$email, FILTER_VALIDATE_EMAIL) ? (string)$email : false; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($value) { return trim(strip_tags((string)$value)); } }
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string)$value); } }
if (!function_exists('get_option')) { function get_option($key, $default = false) { return $GLOBALS['astraea_test_options'][$key] ?? $default; } }
if (!function_exists('update_option')) { function update_option($key, $value, $autoload = null) { $GLOBALS['astraea_test_options'][$key] = $value; return true; } }
if (!function_exists('delete_option')) { function delete_option($key) { unset($GLOBALS['astraea_test_options'][$key]); return true; } }
require_once $root . '/astraea-core/Exceptions/StorageException.php';
require_once $root . '/astraea-core/Mail/Security/OAuthEndpointPolicy.php';
require_once $root . '/astraea-core/Mail/SmtpConfig.php';
require_once $root . '/astraea-core/Mail/Store/EncryptedConfigStore.php';
require_once $root . '/astraea-core/Mail/Store/EncryptedRecordStore.php';
require_once $root . '/wp-includes/PHPMailer/OAuthTokenProvider.php';
require_once $root . '/astraea-core/Mail/Transport/OAuthTokenService.php';
require_once $root . '/astraea-core/Mail/Transport/OAuthProvider.php';
try {
    $GLOBALS['astraea_test_options'] = [];
    $smtpSecret = '  p\\ass:word!  ';
    $cfg = new \Astraea\Mail\SmtpConfig(
        enabled: true,
        provider: 'custom',
        host: 'smtp.example.com',
        port: 587,
        encryption: 'starttls',
        auth: true,
        authType: 'login',
        username: 'user@example.com',
        password: $smtpSecret,
        oauthTokenUrl: '',
        oauthClientId: '',
        oauthClientSecret: 'oauth-client-secret-release-test',
        oauthRefreshToken: 'oauth-refresh-token-release-test',
        oauthScope: '',
        fromEmail: 'mail@example.com',
        fromName: 'Astraea',
        forceFromEmail: true,
        forceFromName: true,
        returnPath: false,
        timeout: 10,
        allowPrivateTarget: false,
        dkimEnabled: false,
        dkimDomain: '',
        dkimSelector: '',
        dkimIdentity: '',
        dkimPrivateKey: '',
    );
    \Astraea\Mail\Store\EncryptedConfigStore::save($cfg);
    $rawMailConfig = $GLOBALS['astraea_test_options'][\Astraea\Mail\Settings::CONFIG_OPTION] ?? '';
    check('SMTP config stored as Astraea AEAD envelope', is_string($rawMailConfig) && str_starts_with($rawMailConfig, 'vgt:a1:'));
    check('SMTP plaintext password absent from persisted option', is_string($rawMailConfig) && !str_contains($rawMailConfig, $smtpSecret));
    check('OAuth secrets absent from persisted plaintext option', is_string($rawMailConfig) && !str_contains($rawMailConfig, 'oauth-client-secret-release-test') && !str_contains($rawMailConfig, 'oauth-refresh-token-release-test'));
    $loadedCfg = \Astraea\Mail\Store\EncryptedConfigStore::load();
    check('SMTP encrypted config round-trip preserves credential bytes', hash_equals($smtpSecret, $loadedCfg->password));
} catch (Throwable $e) {
    check('SMTP encrypted config round-trip executable', false, get_class($e));
}


// Native OAuth/XOAUTH2 checks using the production config/provider without remote network access.
try {
    $httpRejected = false;
    try { \Astraea\Mail\Security\OAuthEndpointPolicy::normalizeHttpsUrl('http://oauth.example.com/token'); }
    catch (\Astraea\Exceptions\SecurityException) { $httpRejected = true; }
    check('OAuth token endpoint rejects non-HTTPS URL', $httpRejected);
    $privateOauthRejected = false;
    try { \Astraea\Mail\Security\OAuthEndpointPolicy::assertAllowed('https://127.0.0.1/token'); }
    catch (\Astraea\Exceptions\SecurityException) { $privateOauthRejected = true; }
    check('OAuth token endpoint rejects private/loopback targets', $privateOauthRejected);

    putenv('ASTRAEA_SMTP_OAUTH_ACCESS_TOKEN=release-test-access-token-1234567890');
    $oauthCfg = new \Astraea\Mail\SmtpConfig(
        enabled: true,
        provider: 'microsoft365',
        host: 'smtp.office365.com',
        port: 587,
        encryption: 'starttls',
        auth: true,
        authType: 'xoauth2',
        username: 'user@example.com',
        password: '',
        oauthTokenUrl: 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        oauthClientId: 'client-id',
        oauthClientSecret: '',
        oauthRefreshToken: '',
        oauthScope: 'https://outlook.office.com/SMTP.Send offline_access',
        fromEmail: 'user@example.com',
        fromName: 'Astraea',
        forceFromEmail: true,
        forceFromName: true,
        returnPath: false,
        timeout: 10,
        allowPrivateTarget: false,
        dkimEnabled: false,
        dkimDomain: '',
        dkimSelector: '',
        dkimIdentity: '',
        dkimPrivateKey: '',
    );
    $oauthCfg->assertOperational(false);
    $oauth64 = (new \Astraea\Mail\Transport\OAuthProvider($oauthCfg))->getOauth64();
    $decodedOauth = base64_decode($oauth64, true);
    check('XOAUTH2 provider emits RFC-style bearer SASL payload', is_string($decodedOauth) && str_contains($decodedOauth, "user=user@example.com\x01auth=Bearer release-test-access-token-1234567890\x01\x01"));
    putenv('ASTRAEA_SMTP_OAUTH_ACCESS_TOKEN');

    \Astraea\Mail\Store\EncryptedRecordStore::saveObject(\Astraea\Mail\Settings::OAUTH_CACHE_OPTION, \Astraea\Mail\Settings::OAUTH_CACHE_AAD, [
        'fingerprint' => 'fp', 'access_token' => 'oauth-sensitive-cache-token', 'expires_at' => time() + 3600,
    ]);
    $rawOauthCache = $GLOBALS['astraea_test_options'][\Astraea\Mail\Settings::OAUTH_CACHE_OPTION] ?? '';
    check('OAuth cached access token is encrypted at rest', is_string($rawOauthCache) && str_starts_with($rawOauthCache, 'vgt:a1:') && !str_contains($rawOauthCache, 'oauth-sensitive-cache-token'));
    \Astraea\Mail\Transport\OAuthTokenService::clearCache();
} catch (Throwable $e) {
    putenv('ASTRAEA_SMTP_OAUTH_ACCESS_TOKEN');
    check('OAuth/XOAUTH2 production checks executable', false, get_class($e));
}

// Real Vault crypto/container round-trip using production classes.
require_once $root . '/astraea-core/Vault/src/Autoloader.php';
\Astraea\Vault\Autoloader::register($root . '/astraea-core/Vault/src');
try {
    $crypto = new \Astraea\Vault\Crypto\CryptoService();
    $crypto->assertAvailable();
    $master = random_bytes(32);
    $id = '12345678-1234-4123-8123-123456789abc';
    $tmp = tempnam(sys_get_temp_dir(), 'avb-test-');
    if (!is_string($tmp)) throw new RuntimeException('tempnam failed');
    $h = fopen($tmp, 'w+b');
    if (!is_resource($h)) throw new RuntimeException('open failed');
    $writer = new \Astraea\Vault\Backup\ContainerWriter($h, $crypto, $id, $master, 'test');
    $writer->writeRecord(\Astraea\Vault\Backup\ContainerWriter::TYPE_FILE_START, '{"path":"example.txt"}', true);
    $writer->writeRecord(\Astraea\Vault\Backup\ContainerWriter::TYPE_FILE_CHUNK, str_repeat('Astraea-', 1000), true);
    $writer->writeRecord(\Astraea\Vault\Backup\ContainerWriter::TYPE_FILE_END, '{"sha256":"test"}');
    $writer->finalize(['files' => 1]);
    $writer->close();

    $r = fopen($tmp, 'rb');
    $reader = new \Astraea\Vault\Backup\ContainerReader($r, $crypto, $master);
    $end = $reader->consumeAndVerify();
    $reader->close();
    check('AVB production round-trip', ($end['files'] ?? null) === 1);

    $bytes = file_get_contents($tmp);
    if (!is_string($bytes)) throw new RuntimeException('read failed');
    $tampered = $bytes;
    $offset = max(20, intdiv(strlen($tampered), 2));
    $tampered[$offset] = chr(ord($tampered[$offset]) ^ 0x01);
    $tamperPath = $tmp . '.tamper';
    file_put_contents($tamperPath, $tampered);
    $caught = false;
    try {
        $tr = fopen($tamperPath, 'rb');
        $reader = new \Astraea\Vault\Backup\ContainerReader($tr, $crypto, $master);
        $reader->consumeAndVerify();
    } catch (Throwable) { $caught = true; }
    check('AVB ciphertext tampering rejected', $caught);

    $truncated = substr($bytes, 0, max(1, strlen($bytes) - 17));
    $truncPath = $tmp . '.trunc';
    file_put_contents($truncPath, $truncated);
    $caught = false;
    try {
        $tr = fopen($truncPath, 'rb');
        $reader = new \Astraea\Vault\Backup\ContainerReader($tr, $crypto, $master);
        $reader->consumeAndVerify();
    } catch (Throwable) { $caught = true; }
    check('AVB truncation rejected', $caught);

    @unlink($tmp); @unlink($tamperPath); @unlink($truncPath);
    $crypto->wipe($master);
} catch (Throwable $e) {
    check('AVB production crypto tests executable', false, get_class($e));
}


// Secure Genesis installer / ThroneGuard first-install hardening checks.
$genesis = source($root . '/astraea-core/Installer/SecureGenesis.php');
$installer = source($root . '/wp-admin/install.php');
$genesisView = source($root . '/wp-admin/includes/astraea-secure-genesis-view.php');
$genesisJs = source($root . '/wp-admin/js/astraea-secure-genesis.js');
$setupConfig = source($root . '/wp-admin/setup-config.php');
$throne = source($root . '/astraea-core/GeDefense/includes/modules/throneguard/class-vis-throne-guard.php');
$dashboardCore = source($root . '/astraea-core/GeDefense/includes/dashboard/class-vis-dashboard-core.php');

check('Secure Genesis kernel service exists', str_contains($genesis, 'final class SecureGenesis') && str_contains($genesis, 'SECURITY_APPLYING') && str_contains($genesis, 'SECURITY_VERIFYING'));
check('Installer invokes Secure Genesis transaction after wp_install', strpos($installer, 'wp_install(') !== false && strpos($installer, 'beginTransaction(') > strpos($installer, 'wp_install(') && strpos($installer, 'compile(') > strpos($installer, 'beginTransaction('));
check('Interrupted Secure Genesis can resume safely', str_contains($installer, 'SecureGenesis::canResume()') && str_contains($installer, 'SecureGenesis::resume()') && str_contains($genesis, 'RESUME_HASH_OPTION') && str_contains($genesis, "'httponly' => true") && str_contains($genesis, "'samesite' => 'Strict'"));
check('Interrupted recovery-key commit can be reprovisioned', str_contains($genesis, 'provisionRecoveryKeyForResume') && str_contains($installer, 'astraea_genesis_recovery_retry') && str_contains($genesisView, 'astraea_render_genesis_recovery_resume'));
check('ThroneGuard installer recovery key uses Argon2id', str_contains($throne, 'install_recovery_key') && str_contains($throne, 'PASSWORD_ARGON2ID') && !str_contains($throne, 'password_hash($newKey, PASSWORD_DEFAULT)'));
check('Secure Genesis enforces Master password server-side', str_contains($genesis, 'validateMasterPassword') && str_contains($installer, 'validateMasterPassword') && str_contains($genesis, 'at least 16 characters') && !str_contains($genesisView, 'accept a weak password'));
check('Synthetic ThroneGuard SYSTEM_INIT event removed', !str_contains($throne, "'action'    => 'SYSTEM_INIT'") && !str_contains($throne, 'init-001'));
check('Secure Genesis provisions user by ID without current session dependency', str_contains($throne, 'provision_user_as_master(int $userId)') && str_contains($genesis, 'provision_user_as_master($userId)'));
check('ThroneGuard is mandatory in Secure Genesis custom profile', str_contains($genesis, "'throneguard_enabled' => 1") && str_contains($genesisView, 'Mandatory in Secure Genesis'));
check('Installer anti-lockout trusts only TCP peer', str_contains($genesis, 'REMOTE_ADDR') && !str_contains($genesis, 'HTTP_X_FORWARDED_FOR') && !str_contains($genesis, 'HTTP_CF_CONNECTING_IP'));
check('Installer anti-lockout is temporary and self-removing', str_contains($genesis, 'astraea_genesis_temporary_allowlist') && str_contains($genesis, 'cleanupTemporaryAllowlist') && str_contains($genesis, "delete_option('astraea_genesis_runtime_verify_pending')"));
check('Secure Genesis stores no plaintext recovery key', str_contains($genesis, '[\'user_id\' => $userId, \'plan\' => $plan]') && str_contains($throne, "update_option('vis_throneguard_superkey_hash'") && !str_contains($genesis, '\'recovery_key\' => $recoveryKey'));
check('Secure Genesis provides evidence preflight', str_contains($genesis, 'preflightBlocksInstallation') && str_contains($genesis, "'argon2id'") && str_contains($genesis, "'keyring_storage'"));
check('Secure Genesis enforces database version baseline', str_contains($genesis, 'RuntimeCheck::checkDatabaseServerVersion') && str_contains($genesis, 'MySQL >= 8.0 / MariaDB >= 10.11'));
check('Secure Genesis treats wp-config group/world permissions as non-ideal', str_contains($genesis, '($configPerms & 0077) === 0'));
check('Installer has ten-step Astraea security UX', str_contains($genesisView, "'Preflight','Site','Master','Profile','ThroneGuard','GeDefense','Network','Privacy','Compile','Launch'") && str_contains($genesisJs, 'data-genesis-step'));
check('Database setup uses randomized Astraea table prefix', str_contains($setupConfig, "'ast_' . substr( bin2hex( random_bytes( 4 ) )") && !str_contains($setupConfig, 'value="wp_"'));
check('Database password is not trimmed by installer', str_contains($setupConfig, '$pwd    = wp_unslash( (string) $_POST[\'pwd\'] );') && !str_contains($setupConfig, '$pwd    = trim('));
check('wp-config is hardened owner-only', str_contains($setupConfig, 'chmod( $path_to_wp_config, 0600 )') && !str_contains($setupConfig, 'chmod( $path_to_wp_config, 0666 )'));
check('Installer provisions external Astraea secret store when possible', str_contains($setupConfig, 'astraea_provision_install_secret_storage') && str_contains($setupConfig, 'ASTRAEA_MASTER_KEY_FILE') && str_contains($setupConfig, 'ASTRAEA_KEYRING_FILE') && str_contains($setupConfig, '0700') && str_contains($setupConfig, '0600'));
check('Secure Genesis reports real master-key and keyring posture', str_contains($genesis, "'master_key' =>") && str_contains($genesis, 'WordPress salt-derived compatibility key in use') && str_contains($genesis, 'external rotation store ready outside webroot'));
check('Secure Genesis schedules first-boot runtime verification', str_contains($genesis, 'astraea_genesis_runtime_verify_pending') && str_contains($genesis, 'runPendingRuntimeVerification'));
check('GeDefense wizard auto-completion respects Secure Genesis state', str_contains($dashboardCore, 'astraea_install_state') && str_contains($dashboardCore, "'READY'"));
check('Secure Genesis refuses remote salt dependency', !str_contains($setupConfig, 'api.wordpress.org/secret-key') && !str_contains($setupConfig, 'wp_remote_get('));
check('Public HTTP installer transport is fail-closed', str_contains($genesis, '$httpsBlocking') && str_contains($genesis, 'unencrypted public installer transport rejected'));
check('Hades is deferred during genesis to prevent lockout', str_contains($genesis, 'Hades is intentionally deferred') && !str_contains($genesis, "custom['hades']"));
check('Secure Genesis has no external UI dependencies', !preg_match('#https?://[^\"\']+#i', $genesisJs) && !containsAny($genesisView, ['cdn.jsdelivr.net', 'unpkg.com', 'cdnjs.cloudflare.com', 'fonts.googleapis.com']));

foreach ($results as [$status, $name, $detail]) {
    printf("[%s] %s%s\n", $status, $name, $detail !== '' ? " — {$detail}" : '');
}
printf("\nSummary: %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
