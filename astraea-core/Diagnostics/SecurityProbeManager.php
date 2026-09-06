<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Diagnostics;

use Astraea\Crypto\CryptoService;
use Astraea\Crypto\KeyContext;
use Astraea\Crypto\Keyring;
use Astraea\Security\FileGuard;
use Astraea\Security\HeaderPolicyService;
use Astraea\Security\SecurityEventManager;
use Astraea\Security\ReleaseIntegrity;
use Astraea\Database\MigrationRunner;
use Astraea\Auth\PepperManager;
use Astraea\Auth\SessionManager;

/**
 * Real Health & Security Probe Manager.
 *
 * Replaces hardcoded "OPTIMAL" / "SYSTEM NOMINAL" claims with deterministic,
 * real-time subsystem probing adhering strictly to Masterprompt Section 16 & 18.
 *
 * @package Astraea\Diagnostics
 */
final class SecurityProbeManager {

    /**
     * Probe Cerberus L0 Perimeter Firewall.
     *
     * @return array{status: HealthStatus, label: string, details: string}
     */
    public static function probeCerberus(): array {
        if (!class_exists('\\VIS_Cerberus') && !class_exists('\\Astraea\\GeDefense\\GeDefenseKernel')) {
            return [
                'status'  => HealthStatus::DISABLED,
                'label'   => 'Inactive',
                'details' => 'Cerberus L0 engine class not mounted.',
            ];
        }

        try {
            if (class_exists('\\VIS_Cerberus')) {
                \VIS_Cerberus::instance();
            }
            return [
                'status'  => HealthStatus::HEALTHY,
                'label'   => 'Active',
                'details' => 'In-memory perimeter inspection operational.',
            ];
        } catch (\Throwable $e) {
            return [
                'status'  => HealthStatus::DEGRADED,
                'label'   => 'Degraded',
                'details' => 'Perimeter engine failed its runtime probe. Consult sanitized Security Events.',
            ];
        }
    }

    /**
     * Probe Zeus & Core Cryptographic Subsystem.
     *
     * @return array{status: HealthStatus, label: string, details: string}
     */
    public static function probeZeusCrypto(): array {
        $hasArgon = defined('PASSWORD_ARGON2ID');
        $hasSodium = extension_loaded('sodium');

        if (!$hasArgon || !$hasSodium) {
            return [
                'status'  => HealthStatus::CRITICAL,
                'label'   => 'Critical',
                'details' => 'Missing Argon2id or sodium extension.',
            ];
        }

        // Live self-test: encrypt and decrypt a micro-payload
        try {
            $testPlain = 'probe:crypto:' . microtime(true);
            $testAad = 'probe:aad';
            $cipher = CryptoService::encrypt($testPlain, KeyContext::SECRETS, $testAad);
            $decrypted = CryptoService::decrypt($cipher, KeyContext::SECRETS, $testAad);

            if ($decrypted !== $testPlain) {
                return [
                    'status'  => HealthStatus::CRITICAL,
                    'label'   => 'Integrity Fault',
                    'details' => 'AEAD self-test failed to match plaintext.',
                ];
            }

            return [
                'status'  => HealthStatus::HEALTHY,
                'label'   => 'Active',
                'details' => 'Argon2id + Sodium AEAD verified via live round-trip.',
            ];
        } catch (\Throwable $e) {
            return [
                'status'  => HealthStatus::WARNING,
                'label'   => 'Degraded',
                'details' => 'Crypto live round-trip could not be completed. Consult sanitized Security Events.',
            ];
        }
    }

    /**
     * Probe the actual GeDefense Aegis DPI request-inspection engine.
     *
     * @return array{status: HealthStatus, label: string, details: string}
     */
    public static function probeAegis(): array {
        $config = function_exists('get_option') ? get_option('vis_config', []) : [];
        $enabled = is_array($config) && !empty($config['aegis_enabled']);
        $classLoaded = class_exists('\\VIS_Aegis');

        if (!$enabled) {
            return [
                'status' => HealthStatus::DISABLED,
                'label' => 'Disabled',
                'details' => 'GeDefense Aegis DPI request inspection is not enabled.',
            ];
        }
        if (!$classLoaded) {
            return [
                'status' => HealthStatus::WARNING,
                'label' => 'Configured / Not Loaded',
                'details' => 'Aegis is enabled in policy but its runtime class is not loaded.',
            ];
        }
        return [
            'status' => HealthStatus::HEALTHY,
            'label' => 'Active',
            'details' => 'GeDefense Aegis DPI runtime is enabled and loaded.',
        ];
    }

    /**
     * Probe HTTP/browser policy enforcement separately from Aegis DPI.
     * Titan is authoritative when enabled; Astraea HeaderPolicy is the baseline/fallback.
     *
     * @return array{status: HealthStatus, label: string, details: string}
     */
    public static function probeHttpSecurity(): array {
        $config = function_exists('get_option') ? get_option('vis_config', []) : [];
        $titanEnabled = is_array($config) && !empty($config['titan_enabled']);
        $titanLoaded = class_exists('\\VIS_Titan');
        $baselineLoaded = class_exists(HeaderPolicyService::class);
        $isHttps = $baselineLoaded && HeaderPolicyService::isHttps();
        $hsts = $baselineLoaded && HeaderPolicyService::isHstsEnabled();

        if ($titanEnabled && $titanLoaded) {
            return [
                'status' => $isHttps ? HealthStatus::HEALTHY : HealthStatus::DEGRADED,
                'label' => $isHttps ? 'Titan Active' : 'Titan Active / HTTP',
                'details' => $isHttps
                    ? 'GeDefense Titan is the active HTTP/browser policy engine; Astraea baseline remains available as fallback.'
                    : 'Titan is loaded, but HTTPS transport is not active for this request.',
            ];
        }
        if ($titanEnabled && !$titanLoaded) {
            return [
                'status' => HealthStatus::WARNING,
                'label' => 'Titan Not Loaded',
                'details' => 'Titan is enabled in GeDefense policy but its runtime class is not loaded.',
            ];
        }
        if (!$baselineLoaded) {
            return [
                'status' => HealthStatus::DISABLED,
                'label' => 'Disabled',
                'details' => 'Neither Titan nor Astraea HeaderPolicy is available.',
            ];
        }
        if (!$isHttps) {
            return [
                'status' => HealthStatus::DEGRADED,
                'label' => 'Baseline / HTTP',
                'details' => 'Astraea baseline response headers are available, but HTTPS-dependent controls are dormant.',
            ];
        }
        return [
            'status' => $hsts ? HealthStatus::HEALTHY : HealthStatus::WARNING,
            'label' => $hsts ? 'Baseline Active' : 'Baseline / HSTS Off',
            'details' => $hsts
                ? 'Astraea baseline response headers and HSTS are active; Titan is disabled.'
                : 'Astraea baseline response headers are active; HSTS is intentionally disabled by policy.',
        ];
    }


    /**
     * Probe FileGuard validation hooks and server-side uploads execution barrier independently.
     *
     * @return array<string,mixed>
     */
    public static function probeFileGuard(): array {
        $uploadDir = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => ''];
        $base = is_array($uploadDir) ? (string)($uploadDir['basedir'] ?? '') : '';
        if ($base === '' || !is_dir($base)) {
            return [
                'status' => HealthStatus::UNKNOWN,
                'label' => 'Upload Path Unknown',
                'details' => 'Upload directory is not available for evidence collection.',
                'upload_filter' => false,
                'mime_filter' => false,
                'apache_barrier' => false,
            ];
        }

        $uploadFilter = function_exists('has_filter') && has_filter('wp_handle_upload_prefilter', [FileGuard::class, 'inspectUpload']) !== false;
        $mimeFilter = function_exists('has_filter') && has_filter('wp_check_filetype_and_ext', [FileGuard::class, 'verifyMimeAndExtension']) !== false;
        $apacheBarrier = false;
        $ht = $base . '/.htaccess';
        if (is_file($ht) && is_readable($ht)) {
            $content = file_get_contents($ht);
            $apacheBarrier = is_string($content)
                && (str_contains($content, 'VisionGaia Titan Upload Guard')
                    || (str_contains($content, '<FilesMatch') && (str_contains($content, 'php_flag engine off') || str_contains($content, 'Require all denied'))));
        }

        if (!$uploadFilter || !$mimeFilter) {
            return [
                'status' => HealthStatus::WARNING,
                'label' => 'Validation Degraded',
                'details' => 'One or more Astraea upload validation hooks are not registered.',
                'upload_filter' => $uploadFilter,
                'mime_filter' => $mimeFilter,
                'apache_barrier' => $apacheBarrier,
            ];
        }

        return [
            'status' => HealthStatus::HEALTHY,
            'label' => $apacheBarrier ? 'Validated + Apache Barrier' : 'Validation Active',
            'details' => $apacheBarrier
                ? 'FileGuard upload/MIME validation hooks and an Apache uploads execution barrier are verified.'
                : 'FileGuard upload/MIME validation hooks are verified. Web-server execution blocking is not asserted for this environment.',
            'upload_filter' => true,
            'mime_filter' => true,
            'apache_barrier' => $apacheBarrier,
        ];
    }


    /**
     * Probe Astraea Vault using explicit boot/registration/storage/key evidence.
     *
     * @return array<string,mixed>
     */
    public static function probeVault(): array {
        if (defined('ASTRAEA_VAULT_BOOT_FAILED') && ASTRAEA_VAULT_BOOT_FAILED === true) {
            return [
                'status' => HealthStatus::CRITICAL,
                'label' => 'Initialization Failed',
                'details' => 'Astraea Vault core-native initialization failed; recovery protection is unavailable.',
                'recovery_gate_ready' => false,
                'update_guard_ready' => false,
                'storage_ready' => false,
                'keys_initialized' => false,
                'latest_verified' => false,
                'latest_backup_id' => 'none',
            ];
        }
        if (!class_exists('\\Astraea\\Vault\\Plugin')) {
            return [
                'status' => HealthStatus::DISABLED,
                'label' => 'Not Loaded',
                'details' => 'Astraea Vault core-native module is not loaded.',
            ];
        }

        try {
            $plugin = \Astraea\Vault\Plugin::instance();
            $services = $plugin->services();
            $storageRoot = class_exists('\\Astraea\\Vault\\Config') ? \Astraea\Vault\Config::storageRoot() : '';
            $storageOk = $storageRoot !== '' && is_dir($storageRoot) && is_readable($storageRoot) && is_writable($storageRoot);
            $earlyReady = method_exists($plugin, 'isEarlyRecoveryBooted') && $plugin->isEarlyRecoveryBooted();
            $guardReady = class_exists('\\Astraea\\Vault\\Update\\UpdateGuard') && \Astraea\Vault\Update\UpdateGuard::isRegistered();
            $keysInitialized = isset($services['keys']) && is_object($services['keys']) && method_exists($services['keys'], 'initialized') && $services['keys']->initialized();

            $latestVerified = false;
            $latestId = 'none';
            if (isset($services['backup_repository']) && is_object($services['backup_repository'])) {
                $rows = $services['backup_repository']->all(1);
                $latest = $rows[0] ?? null;
                if (is_array($latest)) {
                    $latestId = (string)($latest['id'] ?? 'none');
                    $latestVerified = hash_equals('verified', (string)($latest['status'] ?? ''));
                }
            }

            $required = $storageOk && $earlyReady && $guardReady && $keysInitialized;
            return [
                'status' => $required ? HealthStatus::HEALTHY : HealthStatus::WARNING,
                'label' => $required ? 'Operational' : 'Degraded',
                'details' => sprintf(
                    'Recovery gate: %s; Update Guard: %s; storage: %s; key slots: %s; latest backup: %s%s.',
                    $earlyReady ? 'verified' : 'not verified',
                    $guardReady ? 'registered' : 'not registered',
                    $storageOk ? 'read/write' : 'unavailable',
                    $keysInitialized ? 'initialized' : 'not initialized',
                    $latestId,
                    $latestVerified ? ' (VERIFIED)' : ''
                ),
                'recovery_gate_ready' => $earlyReady,
                'update_guard_ready' => $guardReady,
                'storage_ready' => $storageOk,
                'keys_initialized' => $keysInitialized,
                'latest_verified' => $latestVerified,
                'latest_backup_id' => $latestId,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => HealthStatus::WARNING,
                'label' => 'Probe Failed',
                'details' => 'Vault health evidence could not be collected.',
            ];
        }
    }


    /**
     * Probe Database Connection & Schema State.
     *
     * @return array{status: HealthStatus, label: string, details: string}
     */
    public static function probeDatabase(): array {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return [
                'status'  => HealthStatus::CRITICAL,
                'label'   => 'Disconnected',
                'details' => 'WordPress database global ($wpdb) unavailable.',
            ];
        }

        $connected = false;
        try {
            $test = $wpdb->get_var('SELECT 1');
            $connected = ($test === '1' || $test === 1);
        } catch (\Throwable) {
            $connected = false;
        }

        if (!$connected && empty($wpdb->last_error)) {
            // In CLI unit test environment without active MySQL server
            return [
                'status'  => HealthStatus::UNKNOWN,
                'label'   => 'CLI Test Mode',
                'details' => 'Standalone CLI runner without active DB connection.',
            ];
        }

        if (!$connected) {
            return [
                'status'  => HealthStatus::CRITICAL,
                'label'   => 'DB Fault',
                'details' => 'Database connectivity probe failed. Internal database error text is intentionally not exposed.',
            ];
        }

        $serverVersion = '';
        if (method_exists($wpdb, 'db_version')) {
            $serverVersion = (string) $wpdb->db_version();
        }

        $isMariaDb = stripos($serverVersion, 'mariadb') !== false;
        $cleanVer = preg_replace('/[^0-9.]/', '', explode('-', $serverVersion)[0] ?? '');
        $versionCompliant = true;
        $versionDetail = '';

        if ($serverVersion !== '') {
            if ($isMariaDb) {
                if ($cleanVer !== '' && version_compare($cleanVer, '10.11.0', '<')) {
                    $versionCompliant = false;
                    $versionDetail = sprintf('MariaDB %s detected (>= 10.11 required for atomic DDL). ', $cleanVer);
                }
            } else {
                if ($cleanVer !== '' && version_compare($cleanVer, '8.0.0', '<')) {
                    $versionCompliant = false;
                    $versionDetail = sprintf('MySQL %s detected (>= 8.0 required for native JSON/indexes). ', $cleanVer);
                }
            }
        }

        $currentDbVer = function_exists('get_option') ? get_option('astraea_db_version') : null;
        if ($currentDbVer === MigrationRunner::ASTRAEA_DB_VERSION) {
            if (!$versionCompliant) {
                return [
                    'status'  => HealthStatus::WARNING,
                    'label'   => 'Legacy DB Engine',
                    'details' => $versionDetail . 'AstraeaOS schema v' . MigrationRunner::ASTRAEA_DB_VERSION . ' active.',
                ];
            }
            return [
                'status'  => HealthStatus::HEALTHY,
                'label'   => 'Synchronized',
                'details' => 'Schema v' . MigrationRunner::ASTRAEA_DB_VERSION . ' confirmed with meta indexes.',
            ];
        }

        return [
            'status'  => HealthStatus::WARNING,
            'label'   => 'Migration Pending',
            'details' => 'Database schema migration pending run.',
        ];
    }

    /**
     * Probe Core Runtime File Integrity against frozen BUILD-MANIFEST.
     *
     * @return array{status: HealthStatus, label: string, details: string}
     */
    public static function probeManifestIntegrity(): array {
        $deep = self::probeIntegrityDeep();
        return [
            'status' => $deep['status'],
            'label' => $deep['label'],
            'details' => $deep['details'],
            'authenticity' => $deep['authenticity'] ?? ReleaseIntegrity::verifyRootManifest(),
        ];
    }

    /**
     * Probe Morpheus RASP runtime. Mere source-file presence is never considered healthy.
     *
     * @return array<string,mixed>
     */
    public static function probeMorpheus(): array {
        $config = function_exists('get_option') ? get_option('vis_config', []) : [];
        $enabled = is_array($config) && !empty($config['morpheus_enabled']);
        $runtimeLoaded = class_exists('\\Morpheus') || class_exists('\\Morpheus_Hypervisor');
        $morpheusFile = dirname(__DIR__) . '/GeDefense/includes/modules/morpheus/class-vis-morpheus.php';
        $available = is_file($morpheusFile);

        if (!$enabled) {
            return [
                'status' => HealthStatus::DISABLED,
                'label' => $available ? 'Available / Not Active' : 'Unavailable',
                'details' => $available ? 'Morpheus source is present but the module is disabled by policy.' : 'Morpheus module source is not present.',
            ];
        }
        if (!$runtimeLoaded) {
            return [
                'status' => HealthStatus::WARNING,
                'label' => 'Configured / Not Loaded',
                'details' => 'Morpheus is enabled but no runtime or hypervisor class is loaded.',
            ];
        }
        return [
            'status' => HealthStatus::HEALTHY,
            'label' => 'Active',
            'details' => 'Morpheus policy is enabled and its runtime class is loaded.',
        ];
    }


    /** Compute aggregate system health state. */
    public static function getOverallStatus(): HealthStatus {
        $probes = [
            self::probeCerberus()['status'],
            self::probeZeusCrypto()['status'],
            self::probeAegis()['status'],
            self::probeHttpSecurity()['status'],
            self::probeFileGuard()['status'],
            self::probeVault()['status'],
            self::probeDatabase()['status'],
            self::probeManifestIntegrity()['status'],
            self::probeMorpheus()['status'],
        ];
        if (in_array(HealthStatus::CRITICAL, $probes, true)) return HealthStatus::CRITICAL;
        if (in_array(HealthStatus::WARNING, $probes, true)) return HealthStatus::WARNING;
        if (in_array(HealthStatus::DEGRADED, $probes, true)) return HealthStatus::DEGRADED;
        if (in_array(HealthStatus::UNKNOWN, $probes, true)) return HealthStatus::UNKNOWN;
        if (in_array(HealthStatus::DISABLED, $probes, true)) return HealthStatus::DEGRADED;
        return HealthStatus::HEALTHY;
    }


    /**
     * Probe Authentication System State.
     * Passkeys remain explicitly NOT IMPLEMENTED until a real WebAuthn stack exists.
     *
     * @return array<string,mixed>
     */
    public static function probeAuthentication(): array {
        $hasArgon2id = defined('PASSWORD_ARGON2ID');
        $hasPepper = class_exists(PepperManager::class) && PepperManager::isEnabled();
        $currentUserId = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $activeSessions = class_exists(SessionManager::class) ? count(SessionManager::getUserSessions($currentUserId)) : 0;

        $status = $hasArgon2id ? HealthStatus::HEALTHY : HealthStatus::CRITICAL;
        $label = $hasArgon2id ? 'Argon2id Active' : 'Argon2id Missing';
        $details = $hasArgon2id
            ? ($hasPepper ? 'Argon2id password hashing and optional server-side pepper are active.' : 'Argon2id is active; optional server-side pepper is not configured.')
            : 'Argon2id password hashing is unavailable.';

        return [
            'status' => $status,
            'label' => $label,
            'details' => $details,
            'argon2id' => $hasArgon2id,
            'pepper_active' => $hasPepper,
            'pepper_configured' => $hasPepper,
            'previous_peppers_configured' => class_exists(PepperManager::class) ? count(PepperManager::getPreviousPeppers()) : 0,
            'mfa_configured' => false,
            'mfa_status' => 'NOT IMPLEMENTED',
            'passkeys_ready' => false,
            'passkeys_status' => 'NOT IMPLEMENTED',
            'active_sessions' => $activeSessions,
            'last_auth_anomaly' => 'See evidence-only Security Events.',
        ];
    }


    /**
     * Deep Scan Core Runtime File Integrity against frozen BUILD-MANIFEST.
     *
     * @return array<string, mixed>
     */
    public static function probeIntegrityDeep(): array {
        $manifestPath = dirname(__DIR__) . '/BUILD-MANIFEST.json';
        if (!file_exists($manifestPath)) {
            return [
                'status'               => HealthStatus::WARNING,
                'label'                => 'No Manifest',
                'details'              => 'BUILD-MANIFEST.json not found in astraea-core.',
                'manifest_present'     => false,
                'expected_files'       => 0,
                'total_manifest_files' => 0,
                'present_files'        => 0,
                'intact_count'         => 0,
                'modified_files'       => [],
                'missing_files'        => [],
                'unexpected_files'     => [],
                'root_hash'            => 'none',
            ];
        }

        $raw = file_get_contents($manifestPath);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
            return [
                'status'               => HealthStatus::CRITICAL,
                'label'                => 'Corrupted',
                'details'              => 'Build manifest unparseable.',
                'manifest_present'     => false,
                'expected_files'       => 0,
                'total_manifest_files' => 0,
                'present_files'        => 0,
                'intact_count'         => 0,
                'modified_files'       => [],
                'missing_files'        => [],
                'unexpected_files'     => [],
                'root_hash'            => 'none',
            ];
        }

        $expectedFiles = $manifest['files'];
        $coreDir = dirname(__DIR__);
        $modified = [];
        $missing = [];
        $presentCount = 0;

        foreach ($expectedFiles as $relPath => $expectedHash) {
            if (!is_string($relPath) || !is_string($expectedHash) || str_contains($relPath, '..')) {
                $modified[] = ['file' => 'manifest-entry', 'expected' => 'valid path/hash', 'actual' => 'invalid'];
                continue;
            }
            $absPath = $coreDir . '/' . $relPath;
            if (!is_file($absPath) || is_link($absPath)) {
                $missing[] = $relPath;
                continue;
            }
            $presentCount++;
            $currentHash = hash_file('sha256', $absPath);
            if (!is_string($currentHash) || !hash_equals($expectedHash, $currentHash)) {
                $modified[] = [
                    'file'     => $relPath,
                    'expected' => substr($expectedHash, 0, 12) . '...',
                    'actual'   => is_string($currentHash) ? substr($currentHash, 0, 12) . '...' : 'unreadable',
                ];
            }
        }

        $unexpected = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($coreDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item->isFile()) { continue; }
            $absolute = $item->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($coreDir))), '/');
            if ($relative === 'BUILD-MANIFEST.json') { continue; }
            if (!array_key_exists($relative, $expectedFiles)) {
                $unexpected[] = $relative;
            }
        }
        sort($unexpected, SORT_STRING);

        $isClean = empty($modified) && empty($missing) && empty($unexpected);
        $status = $isClean ? HealthStatus::HEALTHY : HealthStatus::CRITICAL;
        $label = $isClean ? 'Verified' : 'Tampered / Modified';
        $details = $isClean
            ? sprintf('All %d core runtime files match the local frozen SHA-256 build manifest.', count($expectedFiles))
            : sprintf('%d modified, %d missing, %d unexpected core files detected.', count($modified), count($missing), count($unexpected));

        $intactCount = count($expectedFiles) - count($modified) - count($missing);
        if (!$isClean) {
            SecurityEventManager::recordOnce(
                SecurityEventManager::SEVERITY_CRITICAL,
                'Integrity',
                'core_manifest_mismatch',
                'Astraea core integrity verification detected modified or missing files.',
                ['modified_count' => count($modified), 'missing_count' => count($missing), 'unexpected_count' => count($unexpected)],
                300
            );
        }

        return [
            'status'               => $status,
            'label'                => $label,
            'details'              => $details,
            'manifest_present'     => true,
            'expected_files'       => count($expectedFiles),
            'total_manifest_files' => count($expectedFiles),
            'present_files'        => $presentCount,
            'intact_count'         => max(0, $intactCount),
            'modified_files'       => $modified,
            'missing_files'        => $missing,
            'unexpected_files'     => $unexpected,
            'root_hash'            => (string) ($manifest['root_hash'] ?? 'unknown'),
            'authenticity'          => ReleaseIntegrity::verifyRootManifest(),
        ];
    }

    /**
     * Deep Scan of Astraea Vault disaster-recovery state using persisted verification metadata.
     * Does not treat an arbitrary .avb file as verified.
     *
     * @return array<string,mixed>
     */
    public static function probeVaultDeep(): array {
        $base = self::probeVault();
        $storageRoot = class_exists('\\Astraea\\Vault\\Config') ? \Astraea\Vault\Config::storageRoot() : '';
        $storageWritable = !empty($base['storage_ready']);
        $records = [];
        $latestVerifiedTime = 'Never';
        $latestBackupTime = 'Never';

        if (class_exists('\\Astraea\\Vault\\Plugin')) {
            try {
                $services = \Astraea\Vault\Plugin::instance()->services();
                if (isset($services['backup_repository']) && is_object($services['backup_repository'])) {
                    foreach ($services['backup_repository']->all(100) as $row) {
                        if (!is_array($row)) { continue; }
                        $created = (string)($row['created_at'] ?? '');
                        $status = (string)($row['status'] ?? 'unknown');
                        if ($latestBackupTime === 'Never' && $created !== '') { $latestBackupTime = $created . ' UTC'; }
                        if ($latestVerifiedTime === 'Never' && hash_equals('verified', $status) && $created !== '') { $latestVerifiedTime = $created . ' UTC'; }
                        $records[] = [
                            'filename' => (string)($row['filename'] ?? ''),
                            'size_formatted' => function_exists('size_format') ? size_format((int)($row['size_bytes'] ?? 0)) : (string)($row['size_bytes'] ?? 0),
                            'created_formatted' => $created !== '' ? $created . ' UTC' : 'Unknown',
                            'timestamp' => $created !== '' ? (int)strtotime($created . ' UTC') : 0,
                            'status' => strtoupper($status),
                            'verified' => hash_equals('verified', $status),
                        ];
                    }
                }
            } catch (\Throwable) {
                $records = [];
            }
        }

        return array_merge($base, [
            'encryption' => 'AES-256-GCM authenticated AVB records',
            'key_architecture' => 'Envelope encryption with domain-separated keys',
            'storage_root' => $storageRoot,
            'storage_path' => $storageRoot,
            'storage_writable' => $storageWritable,
            'offsite_storage' => 'Not configured',
            'disaster_resilience' => 'DEGRADED (Single local node)',
            'resilience_rating' => 'DEGRADED (Single local node)',
            'resilience_notes' => 'Local encrypted backups protect confidentiality and local recovery, but do not survive total host loss. Configure an offsite backend when available.',
            'backup_count' => count($records),
            'container_count' => count($records),
            'latest_backup_time' => $latestBackupTime,
            'last_verification_time' => $latestVerifiedTime,
            'verification_status' => !empty($base['latest_verified']) ? 'VERIFIED' : 'NO VERIFIED BACKUP',
            'recovery_gate' => !empty($base['recovery_gate_ready']) ? 'HOOK VERIFIED' : 'NOT VERIFIED',
            'update_guard' => !empty($base['update_guard_ready']) ? 'HOOK VERIFIED' : 'NOT VERIFIED',
            'service_key' => !empty($base['keys_initialized']) ? 'KEY SLOTS INITIALIZED' : 'NOT INITIALIZED',
            'backups' => $records,
            'containers' => $records,
        ]);
    }


    /**
     * Compute comprehensive evidence-based security posture summary.
     *
     * @return array{overall: HealthStatus, verified_count: int, warning_count: int, not_configured_count: int, last_verified: string}
     */
    public static function getOverallPostureSummary(): array {
        $probes = [
            self::probeCerberus()['status'], self::probeZeusCrypto()['status'], self::probeAegis()['status'],
            self::probeHttpSecurity()['status'], self::probeFileGuard()['status'], self::probeVault()['status'],
            self::probeDatabase()['status'], self::probeManifestIntegrity()['status'], self::probeMorpheus()['status'],
        ];
        $verified = $warnings = $notConfigured = 0;
        foreach ($probes as $st) {
            if ($st === HealthStatus::HEALTHY) $verified++;
            elseif ($st === HealthStatus::WARNING || $st === HealthStatus::DEGRADED || $st === HealthStatus::CRITICAL) $warnings++;
            else $notConfigured++;
        }
        return [
            'overall' => self::getOverallStatus(),
            'verified_count' => $verified,
            'warning_count' => $warnings,
            'not_configured_count' => $notConfigured,
            'last_verified' => gmdate('d M Y · H:i:s') . ' UTC',
        ];
    }

}
