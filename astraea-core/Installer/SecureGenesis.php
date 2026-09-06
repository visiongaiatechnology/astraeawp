<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Installer;

use Astraea\Bootstrap\RuntimeCheck;
use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\StorageException;
use Astraea\Exceptions\ValidationException;

if (!defined('ABSPATH')) {
    exit('Access Denied');
}

final class SecureGenesis
{
    public const STATE_OPTION = 'astraea_install_state';
    private const PLAN_OPTION = 'astraea_secure_genesis_plan';
    private const RESUME_HASH_OPTION = 'astraea_secure_genesis_resume_hash';
    private const RESUME_COOKIE = 'astraea_secure_genesis_resume';
    private const READY = 'READY';

    /** @return array<string,array{label:string,status:string,detail:string,blocking:bool}> */
    public static function preflight(): array
    {
        global $wpdb;
        $wpConfig = ABSPATH . 'wp-config.php';
        $configPerms = is_file($wpConfig) ? (fileperms($wpConfig) & 0777) : 0;
        $configSafe = $configPerms === 0 || (($configPerms & 0077) === 0);
        $https = is_ssl();
        $peerIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $peerValid = filter_var($peerIp, FILTER_VALIDATE_IP) !== false;
        $peerPublic = $peerValid && filter_var($peerIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        $httpsBlocking = !$https && $peerPublic;
        $masterFile = self::configuredPath('ASTRAEA_MASTER_KEY_FILE');
        $keyringFile = self::configuredPath('ASTRAEA_KEYRING_FILE');
        $masterExternal = self::isSecureExternalSecretPath($masterFile, true);
        $keyringExternal = self::isSecureExternalSecretPath($keyringFile, true) && $keyringFile !== null && is_writable(dirname($keyringFile));
        $invalidSalts = ['put your unique phrase here', 'Füge hier deine Zeichenkette ein'];
        $saltFallback = defined('AUTH_KEY') && defined('SECURE_AUTH_KEY') && !in_array((string)AUTH_KEY, $invalidSalts, true) && !in_array((string)SECURE_AUTH_KEY, $invalidSalts, true);
        $dbVersion = isset($wpdb) && $wpdb instanceof \wpdb
            ? (method_exists($wpdb, 'db_server_info') ? (string)$wpdb->db_server_info() : (string)$wpdb->db_version())
            : 'unknown';
        $dbRequirement = $dbVersion !== 'unknown' && class_exists(RuntimeCheck::class)
            ? RuntimeCheck::checkDatabaseServerVersion($dbVersion)
            : ['passed' => $dbVersion !== 'unknown', 'required' => 'MySQL >= 8.0 / MariaDB >= 10.11'];
        $argon = defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true);

        return [
            'php' => ['label' => 'PHP Runtime', 'status' => version_compare(PHP_VERSION, '8.3.0', '>=') ? 'PASS' : 'FAIL', 'detail' => PHP_VERSION, 'blocking' => !version_compare(PHP_VERSION, '8.3.0', '>=')],
            'sodium' => ['label' => 'Sodium', 'status' => extension_loaded('sodium') ? 'PASS' : 'FAIL', 'detail' => extension_loaded('sodium') ? 'native extension available' : 'required extension missing', 'blocking' => !extension_loaded('sodium')],
            'openssl' => ['label' => 'OpenSSL', 'status' => extension_loaded('openssl') ? 'PASS' : 'FAIL', 'detail' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'extension unavailable', 'blocking' => !extension_loaded('openssl')],
            'argon2id' => ['label' => 'Argon2id', 'status' => $argon ? 'PASS' : 'FAIL', 'detail' => $argon ? 'password hashing available' : 'PASSWORD_ARGON2ID unavailable', 'blocking' => !$argon],
            'dom_xml' => ['label' => 'DOM/XML', 'status' => extension_loaded('dom') && class_exists(\DOMDocument::class) ? 'PASS' : 'FAIL', 'detail' => extension_loaded('dom') && class_exists(\DOMDocument::class) ? 'DOMDocument available' : 'required SVG sanitizer dependency missing', 'blocking' => !(extension_loaded('dom') && class_exists(\DOMDocument::class))],
            'database' => ['label' => 'Database', 'status' => !empty($dbRequirement['passed']) ? 'PASS' : 'FAIL', 'detail' => $dbVersion . ' · ' . (string)($dbRequirement['required'] ?? 'version requirement unavailable'), 'blocking' => empty($dbRequirement['passed'])],
            'https' => [
                'label' => 'HTTPS',
                'status' => $https ? 'PASS' : ($httpsBlocking ? 'FAIL' : 'WARN'),
                'detail' => $https ? 'encrypted installer transport active' : ($httpsBlocking ? 'unencrypted public installer transport rejected' : 'HTTP detected on a local/private installer peer'),
                'blocking' => $httpsBlocking,
            ],
            'wp_config' => ['label' => 'wp-config.php permissions', 'status' => $configSafe ? 'PASS' : 'WARN', 'detail' => $configPerms > 0 ? sprintf('%04o', $configPerms) : 'not created yet / unreadable mode', 'blocking' => false],
            'master_key' => [
                'label' => 'Astraea master key',
                'status' => $masterExternal ? 'PASS' : ($saltFallback ? 'WARN' : 'FAIL'),
                'detail' => $masterExternal ? 'external owner-only secret file outside webroot' : ($saltFallback ? 'WordPress salt-derived compatibility key in use' : 'no valid master-key source'),
                'blocking' => !$masterExternal && !$saltFallback,
            ],
            'keyring_storage' => [
                'label' => 'Historical keyring',
                'status' => $keyringExternal ? 'PASS' : 'WARN',
                'detail' => $keyringExternal ? 'external rotation store ready outside webroot' : 'external historical-key store not provisioned; future key rotation requires configuration',
                'blocking' => false,
            ],
            'display_errors' => ['label' => 'PHP display_errors', 'status' => filter_var((string)ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) ? 'WARN' : 'PASS', 'detail' => filter_var((string)ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) ? 'enabled' : 'disabled', 'blocking' => false],
        ];
    }

    public static function preflightBlocksInstallation(): bool
    {
        foreach (self::preflight() as $check) {
            if ($check['blocking'] && $check['status'] === 'FAIL') {
                return true;
            }
        }
        return false;
    }

    public static function generateRecoveryKey(): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return 'ATG-' . implode('-', str_split(strtoupper($raw), 8));
    }

    public static function validateRecoveryKey(string $key): bool
    {
        return preg_match('/^ATG-(?:[A-Z0-9_-]{8}-){4,6}[A-Z0-9_-]{1,8}$/D', $key) === 1
            && strlen($key) >= 48
            && strlen($key) <= 96;
    }

    public static function validateMasterPassword(string $password, string $username, string $email): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        if ($length < 16) throw new ValidationException('Master password must contain at least 16 characters.');
        if ($length > 256 || strlen($password) > 1024) throw new ValidationException('Master password exceeds the secure processing boundary.');
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $password) === 1) {
            throw new SecurityException('Master password contains forbidden control characters.');
        }

        $passwordFolded = function_exists('mb_strtolower') ? mb_strtolower($password, 'UTF-8') : strtolower($password);
        $usernameFolded = function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
        if (strlen($usernameFolded) >= 3 && str_contains($passwordFolded, $usernameFolded)) {
            throw new ValidationException('Master password must not contain the master username.');
        }

        $mailLocal = strstr($email, '@', true);
        if (is_string($mailLocal) && strlen($mailLocal) >= 3) {
            $mailFolded = function_exists('mb_strtolower') ? mb_strtolower($mailLocal, 'UTF-8') : strtolower($mailLocal);
            if (str_contains($passwordFolded, $mailFolded)) {
                throw new ValidationException('Master password must not contain the email account name.');
            }
        }

        $unique = count(array_unique(preg_split('//u', $password, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if ($unique < 5) throw new ValidationException('Master password contains insufficient character diversity.');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function sanitizePlan(array $input): array
    {
        $profile = isset($input['security_profile']) && is_string($input['security_profile']) ? sanitize_key($input['security_profile']) : 'balanced';
        if (!in_array($profile, ['balanced', 'hardened', 'maximum', 'custom'], true)) {
            $profile = 'balanced';
        }
        $boolKeys = [
            'aegis', 'cerberus', 'prometheus', 'titan', 'morpheus', 'morpheus_enforce', 'nemesis', 'styx', 'airlock',
            'throneguard', 'harden_admin', 'throne_lock', 'block_xmlrpc', 'block_rest', 'disable_feeds', 'hide_version',
            'disable_app_passwords', 'vlp', 'dattrack', 'mail_later',
        ];
        $custom = [];
        foreach ($boolKeys as $key) {
            $custom[$key] = !empty($input[$key]);
        }
        return ['security_profile' => $profile, 'custom' => $custom, 'created_at' => time()];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private static function profileConfiguration(array $plan): array
    {
        $profile = (string)($plan['security_profile'] ?? 'balanced');
        $custom = is_array($plan['custom'] ?? null) ? $plan['custom'] : [];
        $balanced = [
            'aegis_enabled' => 1, 'aegis_mode' => 'learning', 'cerberus_enabled' => 1, 'prometheus_enabled' => 1,
            'morpheus_enabled' => 0, 'morpheus_enforce' => 0, 'nemesis_enabled' => 1,
            'styx_enabled' => 1, 'styx_audit_mode' => 1, 'styx_block_wp_telemetry' => 1,
            'airlock_enabled' => 1, 'hades_enabled' => 0, 'titan_enabled' => 1,
            'titan_block_xmlrpc' => 1, 'titan_block_rest' => 0, 'titan_disable_feeds' => 0,
            'titan_hide_version' => 1, 'titan_remove_asset_versions' => 1, 'titan_remove_discovery_links' => 1,
            'titan_anti_enum' => 1, 'titan_application_passwords_mode' => 'audit', 'titan_application_lockdown' => 0,
            'throneguard_enabled' => 1, 'throneguard_harden_admin' => 1, 'throneguard_lock_enabled' => 1,
            'vlp_enabled' => 1, 'module_vlp_enabled' => 1,
        ];
        $hardened = array_merge($balanced, [
            'aegis_mode' => 'strict', 'morpheus_enabled' => 1, 'titan_application_passwords_mode' => 'disable',
            'titan_disable_feeds' => 1, 'titan_includes_guard' => 1, 'titan_login_gatekeeper' => 1,
        ]);
        $maximum = array_merge($hardened, [
            'morpheus_enforce' => 1, 'titan_application_lockdown' => 1, 'titan_block_rest' => 1,
            'gorgon_enabled' => 1, 'filesystem_enabled' => 1, 'kernel_enabled' => 1,
        ]);
        if ($profile === 'hardened') return $hardened;
        if ($profile === 'maximum') return $maximum;
        if ($profile !== 'custom') return $balanced;

        return array_merge($balanced, [
            'aegis_enabled' => !empty($custom['aegis']) ? 1 : 0,
            'cerberus_enabled' => !empty($custom['cerberus']) ? 1 : 0,
            'prometheus_enabled' => !empty($custom['prometheus']) ? 1 : 0,
            'titan_enabled' => !empty($custom['titan']) ? 1 : 0,
            'morpheus_enabled' => !empty($custom['morpheus']) ? 1 : 0,
            'morpheus_enforce' => !empty($custom['morpheus_enforce']) ? 1 : 0,
            'nemesis_enabled' => !empty($custom['nemesis']) ? 1 : 0,
            'styx_enabled' => !empty($custom['styx']) ? 1 : 0,
            'airlock_enabled' => !empty($custom['airlock']) ? 1 : 0,
            // Hades is intentionally deferred until after the first verified login to prevent installer lockout.
            'hades_enabled' => 0,
            'throneguard_enabled' => 1,
            'throneguard_harden_admin' => 1,
            'throneguard_lock_enabled' => 1,
            'titan_block_xmlrpc' => !empty($custom['block_xmlrpc']) ? 1 : 0,
            'titan_block_rest' => !empty($custom['block_rest']) ? 1 : 0,
            'titan_disable_feeds' => !empty($custom['disable_feeds']) ? 1 : 0,
            'titan_hide_version' => !empty($custom['hide_version']) ? 1 : 0,
            'titan_application_passwords_mode' => !empty($custom['disable_app_passwords']) ? 'disable' : 'audit',
            'vlp_enabled' => !empty($custom['vlp']) ? 1 : 0,
            'module_vlp_enabled' => !empty($custom['vlp']) ? 1 : 0,
        ]);
    }

    /** @param array<string,mixed> $plan */
    public static function beginTransaction(int $userId, array $plan, string $recoveryKey): void
    {
        if ($userId <= 0) throw new ValidationException('Invalid installer user identifier.');
        if (!self::validateRecoveryKey($recoveryKey)) throw new SecurityException('Recovery key validation failed.');

        // Persist a resumable transaction before any post-core security commit. The
        // recovery key itself is deliberately NOT persisted in this plan.
        $resumeToken = bin2hex(random_bytes(32));
        if (!update_option(self::STATE_OPTION, 'CORE_INSTALLED', false) && get_option(self::STATE_OPTION) !== 'CORE_INSTALLED') {
            throw new StorageException('Secure Genesis state initialization failed.');
        }
        if (!update_option(self::PLAN_OPTION, ['user_id' => $userId, 'plan' => $plan], false) && !is_array(get_option(self::PLAN_OPTION))) {
            update_option(self::STATE_OPTION, 'SECURITY_FAILED', false);
            throw new StorageException('Secure Genesis plan persistence failed.');
        }
        $resumeHash = hash('sha256', $resumeToken);
        if (!update_option(self::RESUME_HASH_OPTION, $resumeHash, false) && !hash_equals((string)get_option(self::RESUME_HASH_OPTION, ''), $resumeHash)) {
            update_option(self::STATE_OPTION, 'SECURITY_FAILED', false);
            throw new StorageException('Secure Genesis resume credential persistence failed.');
        }
        setcookie(self::RESUME_COOKIE, $resumeToken, [
            'expires' => time() + HOUR_IN_SECONDS,
            'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict',
        ]);

        self::ensureThroneGuardLoaded();
        if (!class_exists('\VIS_Throne_Guard') || !\VIS_Throne_Guard::install_recovery_key($recoveryKey)) {
            update_option(self::STATE_OPTION, 'SECURITY_FAILED', false);
            throw new StorageException('ThroneGuard recovery key provisioning failed.');
        }
    }

    public static function hasRecoveryKeyHash(): bool
    {
        $hash = (string)get_option('vis_throneguard_superkey_hash', '');
        return $hash !== '' && str_starts_with($hash, '$argon2id$');
    }

    public static function provisionRecoveryKeyForResume(string $recoveryKey): void
    {
        if (!self::canResume()) throw new SecurityException('Secure Genesis resume authorization failed.');
        if (!self::validateRecoveryKey($recoveryKey)) throw new ValidationException('A valid ThroneGuard recovery key is required.');
        self::ensureThroneGuardLoaded();
        if (!class_exists('\VIS_Throne_Guard') || !\VIS_Throne_Guard::install_recovery_key($recoveryKey)) {
            update_option(self::STATE_OPTION, 'SECURITY_FAILED', false);
            throw new StorageException('ThroneGuard recovery key reprovisioning failed.');
        }
        update_option(self::STATE_OPTION, 'CORE_INSTALLED', false);
    }

    public static function canResume(): bool
    {
        $state = (string)get_option(self::STATE_OPTION, '');
        if ($state === '' || $state === self::READY) return false;
        if (function_exists('current_user_can') && current_user_can('manage_options')) return true;
        $cookie = $_COOKIE[self::RESUME_COOKIE] ?? '';
        $stored = (string)get_option(self::RESUME_HASH_OPTION, '');
        return is_string($cookie) && preg_match('/^[a-f0-9]{64}$/D', $cookie) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $stored) === 1
            && hash_equals($stored, hash('sha256', $cookie));
    }

    /** @return array<string,array{status:string,detail:string}> */
    public static function resume(): array
    {
        $stored = get_option(self::PLAN_OPTION, []);
        if (!is_array($stored) || !isset($stored['user_id'], $stored['plan']) || !is_array($stored['plan'])) {
            throw new StorageException('Secure Genesis recovery plan unavailable.');
        }
        return self::compile((int)$stored['user_id'], $stored['plan']);
    }

    /** @param array<string,mixed> $plan @return array<string,array{status:string,detail:string}> */
    public static function compile(int $userId, array $plan): array
    {
        if (!self::hasRecoveryKeyHash()) {
            update_option(self::STATE_OPTION, 'SECURITY_FAILED', false);
            throw new SecurityException('ThroneGuard recovery key hash unavailable before security compilation.');
        }
        update_option(self::STATE_OPTION, 'SECURITY_APPLYING', false);
        $report = [];
        self::ensureThroneGuardLoaded();
        if (!class_exists('\\VIS_Throne_Guard')) throw new StorageException('ThroneGuard runtime unavailable.');

        $master = \VIS_Throne_Guard::provision_user_as_master($userId);
        $report['throneguard_master'] = ['status' => $master ? 'PASS' : 'FAIL', 'detail' => $master ? 'Master privilege boundary provisioned' : 'Master role provisioning failed'];
        if (!$master) throw new StorageException('ThroneGuard Master provisioning failed.');

        $config = get_option('vis_config', []);
        $config = is_array($config) ? $config : [];
        $resolved = self::profileConfiguration($plan);
        $peerIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (filter_var($peerIp, FILTER_VALIDATE_IP) !== false) {
            $aegisRaw = (string)($config['aegis_whitelist_ips'] ?? '');
            $prometheusRaw = (string)($config['prometheus_whitelist_ips'] ?? '');
            $zeusRaw = get_option('vgt_zeus_whitelist_ips', []);
            $zeus = is_array($zeusRaw) ? array_values(array_filter($zeusRaw, 'is_string')) : [];
            $temporary = [
                'ip' => $peerIp,
                'aegis_added' => !self::containsLine($aegisRaw, $peerIp),
                'prometheus_added' => !self::containsLine($prometheusRaw, $peerIp),
                'zeus_added' => !in_array($peerIp, $zeus, true),
                'created_at' => time(),
            ];
            $resolved['aegis_whitelist_ips'] = self::appendLine($aegisRaw, $peerIp);
            $resolved['prometheus_whitelist_ips'] = self::appendLine($prometheusRaw, $peerIp);
            if ($temporary['zeus_added']) $zeus[] = $peerIp;
            update_option('vgt_zeus_whitelist_ips', array_values(array_unique($zeus)), false);
            update_option('astraea_genesis_temporary_allowlist', $temporary, false);
        }

        $config = array_merge($config, $resolved);
        if (!update_option('vis_config', $config, false) && get_option('vis_config', null) !== $config) {
            throw new StorageException('GeDefense security profile persistence failed.');
        }
        $report['gedefense_profile'] = ['status' => 'PASS', 'detail' => strtoupper((string)($plan['security_profile'] ?? 'balanced')) . ' profile persisted'];

        $custom = is_array($plan['custom'] ?? null) ? $plan['custom'] : [];
        $secProfile = (string)($plan['security_profile'] ?? 'balanced');
        $vlpEnabled = $secProfile === 'custom' ? !empty($custom['vlp']) : ($secProfile !== 'minimal');
        $dattrackEnabled = $secProfile === 'custom' ? !empty($custom['dattrack']) : ($secProfile !== 'minimal');
        update_option('astraea_vlp_light_enabled', $vlpEnabled, false);
        update_option('astraea_vlp_dattrack_enabled', $dattrackEnabled, false);
        update_option('astraea_vlp_strict_cache', true, false);

        $moduleStates = match ($secProfile) {
            'minimal' => [
                'vault'       => true,
                'gedefense'   => false,
                'vlp'         => false,
                'dattrack'    => false,
                'mail'        => false,
                'performance' => true,
                'media'       => false,
                'redirects'   => false,
                'seo'         => false,
                'forms'       => false,
                'tasks'       => true,
                'database'    => true,
                'maintenance' => false,
                'identity'    => true,
            ],
            'hardened', 'maximum' => [
                'vault'       => true,
                'gedefense'   => true,
                'vlp'         => true,
                'dattrack'    => true,
                'mail'        => true,
                'performance' => true,
                'media'       => true,
                'redirects'   => true,
                'seo'         => true,
                'forms'       => false,
                'tasks'       => true,
                'database'    => true,
                'maintenance' => true,
                'identity'    => true,
            ],
            'custom' => [
                'vault'       => true,
                'gedefense'   => !empty($custom['gedefense']),
                'vlp'         => !empty($custom['vlp']),
                'dattrack'    => !empty($custom['dattrack']),
                'mail'        => !empty($custom['mail']),
                'performance' => !empty($custom['performance']),
                'media'       => !empty($custom['media']),
                'redirects'   => !empty($custom['redirects']),
                'seo'         => !empty($custom['seo']),
                'forms'       => !empty($custom['forms']),
                'tasks'       => true,
                'database'    => true,
                'maintenance' => !empty($custom['maintenance']),
                'identity'    => true,
            ],
            default => [ // balanced
                'vault'       => true,
                'gedefense'   => true,
                'vlp'         => true,
                'dattrack'    => true,
                'mail'        => true,
                'performance' => true,
                'media'       => true,
                'redirects'   => true,
                'seo'         => true,
                'forms'       => false,
                'tasks'       => true,
                'database'    => true,
                'maintenance' => false,
                'identity'    => true,
            ],
        };
        update_option('astraea_module_states', $moduleStates, false);

        $report['privacy_layer'] = ['status' => $vlpEnabled ? 'PASS' : 'WARN', 'detail' => $vlpEnabled ? 'VLP Light enabled with strict cache safety' : 'VLP Light disabled by profile'];
        $report['module_fabric'] = ['status' => 'PASS', 'detail' => strtoupper($secProfile) . ' feature profile modules initialized'];

        \VIS_Throne_Guard::apply_administrator_policy(!empty($config['throneguard_harden_admin']));
        $report['administrator_boundary'] = ['status' => !empty($config['throneguard_harden_admin']) ? 'PASS' : 'WARN', 'detail' => !empty($config['throneguard_harden_admin']) ? 'administrator toxic capabilities restricted' : 'administrator hardening disabled by profile'];

        update_option('vgt_setup_wizard_completed', 1, false);
        update_option(self::STATE_OPTION, 'SECURITY_VERIFYING', false);
        $report = array_merge($report, self::verify($userId));
        foreach ($report as $item) {
            if (($item['status'] ?? 'FAIL') === 'FAIL') {
                update_option(self::STATE_OPTION, 'SECURITY_FAILED', false);
                return $report;
            }
        }

        update_option(self::STATE_OPTION, self::READY, false);
        update_option('astraea_genesis_runtime_verify_pending', 1, false);
        delete_option(self::PLAN_OPTION);
        delete_option(self::RESUME_HASH_OPTION);
        self::clearResumeCookie();
        \VIS_Throne_Guard::log_event('SECURE_GENESIS', 'Astraea Secure Genesis security profile compiled and verified.', 'success', ['profile' => (string)($plan['security_profile'] ?? 'balanced')]);
        return $report;
    }

    /** @return array<string,array{status:string,detail:string}> */
    private static function verify(int $userId): array
    {
        $user = get_userdata($userId);
        $isMaster = $user instanceof \WP_User && in_array(\VIS_Throne_Guard::MASTER_ROLE, (array)$user->roles, true) && $user->has_cap(\VIS_Throne_Guard::MASTER_CAP);
        $superkey = (string)get_option('vis_throneguard_superkey_hash', '');
        $stored = get_option('vis_config', []);
        $stored = is_array($stored) ? $stored : [];
        return [
            'master_verify' => ['status' => $isMaster ? 'PASS' : 'FAIL', 'detail' => $isMaster ? 'Master role verified' : 'Master role missing'],
            'recovery_key' => ['status' => str_starts_with($superkey, '$argon2id$') ? 'PASS' : 'FAIL', 'detail' => str_starts_with($superkey, '$argon2id$') ? 'Argon2id recovery-key hash verified' : 'recovery-key hash unavailable'],
            'throneguard' => ['status' => !empty($stored['throneguard_enabled']) ? 'PASS' : 'FAIL', 'detail' => !empty($stored['throneguard_enabled']) ? 'ThroneGuard enabled' : 'ThroneGuard disabled'],
            'aegis' => ['status' => !empty($stored['aegis_enabled']) ? 'PASS' : 'WARN', 'detail' => !empty($stored['aegis_enabled']) ? 'Aegis configured' : 'Aegis disabled by selected profile'],
            'cerberus' => ['status' => !empty($stored['cerberus_enabled']) ? 'PASS' : 'WARN', 'detail' => !empty($stored['cerberus_enabled']) ? 'Cerberus configured' : 'Cerberus disabled by selected profile'],
            'titan' => ['status' => !empty($stored['titan_enabled']) ? 'PASS' : 'WARN', 'detail' => !empty($stored['titan_enabled']) ? 'Titan configured' : 'Titan disabled by selected profile'],
            'anti_lockout' => ['status' => filter_var((string)($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP) !== false ? 'PASS' : 'WARN', 'detail' => 'installer peer allowlist evaluated'],
        ];
    }

    public static function runPendingRuntimeVerification(): void
    {
        if ((string)get_option(self::STATE_OPTION, '') !== self::READY || (int)get_option('astraea_genesis_runtime_verify_pending', 0) !== 1) {
            return;
        }

        $previousHealth = get_option('astraea_genesis_runtime_health', []);
        if (is_array($previousHealth) && isset($previousHealth['verified_at']) && (time() - (int)$previousHealth['verified_at']) < 60) {
            return;
        }

        $config = get_option('vis_config', []);
        $config = is_array($config) ? $config : [];
        $checks = [
            'throneguard' => empty($config['throneguard_enabled']) || class_exists('VIS_Throne_Guard', false),
            'aegis' => empty($config['aegis_enabled']) || class_exists('VIS_Aegis', false),
            'cerberus' => empty($config['cerberus_enabled']) || class_exists('VIS_Cerberus', false),
            'titan' => empty($config['titan_enabled']) || class_exists('VIS_Titan', false),
            'prometheus' => empty($config['prometheus_enabled']) || class_exists('\VisionGaia\GeDefense\Modules\Prometheus\Prometheus', false),
        ];
        $healthy = !in_array(false, $checks, true);
        update_option('astraea_genesis_runtime_health', [
            'verified_at' => time(),
            'version' => defined('ASTRAEA_VERSION') ? ASTRAEA_VERSION : 'unknown',
            'status' => $healthy ? 'VERIFIED' : 'DEGRADED',
            'checks' => $checks,
        ], false);
        if ($healthy) {
            self::cleanupTemporaryAllowlist();
            delete_option('astraea_genesis_runtime_verify_pending');
        }

        if (class_exists('\Astraea\Security\SecurityEventManager')) {
            \Astraea\Security\SecurityEventManager::recordEvent(
                $healthy ? \Astraea\Security\SecurityEventManager::SEVERITY_INFO : \Astraea\Security\SecurityEventManager::SEVERITY_WARNING,
                'SECURE_GENESIS',
                $healthy ? 'runtime_verified' : 'runtime_degraded',
                $healthy ? 'Secure Genesis first-boot runtime verification succeeded.' : 'Secure Genesis first-boot runtime verification detected a degraded module state.',
                ['checks' => $checks]
            );
        }
    }

    public static function initIncompleteInstallGuard(): void
    {
        self::runPendingRuntimeVerification();
        if (!function_exists('add_action')) return;
        add_action('admin_init', static function (): void {
            $state = (string)get_option(self::STATE_OPTION, '');
            if ($state === '' || $state === self::READY || wp_doing_ajax() || !current_user_can('manage_options')) return;
            $script = isset($_SERVER['SCRIPT_NAME']) ? basename((string)$_SERVER['SCRIPT_NAME']) : '';
            if ($script === 'install.php') return;
            wp_safe_redirect(admin_url('install.php?step=3'));
            exit;
        }, 1);
    }

    private static function configuredPath(string $name): ?string
    {
        $value = getenv($name);
        if ((!is_string($value) || trim($value) === '') && defined($name)) {
            $constant = constant($name);
            $value = is_string($constant) ? $constant : '';
        }
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function isSecureExternalSecretPath(?string $path, bool $mustExist): bool
    {
        if ($path === null) return false;
        $webroot = realpath(ABSPATH);
        $directory = realpath(dirname($path));
        if ($webroot === false || $directory === false || !is_dir($directory)) return false;
        $destination = $directory . DIRECTORY_SEPARATOR . basename($path);
        $webrootPrefix = rtrim($webroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($destination === $webroot || str_starts_with($destination, $webrootPrefix)) return false;
        if (is_link($path) || is_link($directory)) return false;
        if ($mustExist && (!is_file($path) || !is_readable($path))) return false;
        if (!$mustExist && !is_writable($directory)) return false;
        if (DIRECTORY_SEPARATOR === '/' && is_file($path)) {
            $mode = fileperms($path);
            if ($mode === false || ($mode & 0077) !== 0) return false;
        }
        return true;
    }

    private static function ensureThroneGuardLoaded(): void
    {
        if (class_exists('\\VIS_Throne_Guard')) return;
        $path = defined('VIS_PATH') ? VIS_PATH . 'includes/modules/throneguard/class-vis-throne-guard.php' : ASTRAEA_CORE_DIR . 'GeDefense/includes/modules/throneguard/class-vis-throne-guard.php';
        if (!is_readable($path)) throw new StorageException('ThroneGuard runtime file unavailable.');
        require_once $path;
    }

    private static function containsLine(string $raw, string $value): bool
    {
        $items = array_values(array_filter(array_map('trim', preg_split('/\R/u', $raw) ?: []), 'strlen'));
        return in_array($value, $items, true);
    }

    private static function appendLine(string $raw, string $value): string
    {
        $items = array_values(array_filter(array_map('trim', preg_split('/\R/u', $raw) ?: []), 'strlen'));
        if (!in_array($value, $items, true)) $items[] = $value;
        return implode("\n", $items);
    }

    private static function removeLine(string $raw, string $value): string
    {
        $items = array_values(array_filter(array_map('trim', preg_split('/\R/u', $raw) ?: []), static fn(string $item): bool => $item !== ''));
        $items = array_values(array_filter($items, static fn(string $item): bool => !hash_equals($item, $value)));
        return implode("\n", $items);
    }

    private static function cleanupTemporaryAllowlist(): void
    {
        $temporary = get_option('astraea_genesis_temporary_allowlist', []);
        if (!is_array($temporary) || !isset($temporary['ip']) || !is_string($temporary['ip'])) return;
        $ip = $temporary['ip'];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            delete_option('astraea_genesis_temporary_allowlist');
            return;
        }

        $config = get_option('vis_config', []);
        $config = is_array($config) ? $config : [];
        if (!empty($temporary['aegis_added'])) {
            $config['aegis_whitelist_ips'] = self::removeLine((string)($config['aegis_whitelist_ips'] ?? ''), $ip);
        }
        if (!empty($temporary['prometheus_added'])) {
            $config['prometheus_whitelist_ips'] = self::removeLine((string)($config['prometheus_whitelist_ips'] ?? ''), $ip);
        }
        update_option('vis_config', $config, false);

        if (!empty($temporary['zeus_added'])) {
            $zeusRaw = get_option('vgt_zeus_whitelist_ips', []);
            $zeus = is_array($zeusRaw) ? array_values(array_filter($zeusRaw, 'is_string')) : [];
            $zeus = array_values(array_filter($zeus, static fn(string $item): bool => !hash_equals($item, $ip)));
            update_option('vgt_zeus_whitelist_ips', $zeus, false);
        }
        delete_option('astraea_genesis_temporary_allowlist');
    }

    private static function clearResumeCookie(): void
    {
        setcookie(self::RESUME_COOKIE, '', [
            'expires' => time() - HOUR_IN_SECONDS,
            'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict',
        ]);
    }
}
