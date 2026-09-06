<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Diagnostics;

use Astraea\Version;
use Astraea\Modules\ModuleRegistry;
use Astraea\Compatibility\CompatibilityManager;
use Astraea\Auth\StepUpAuthService;
use Astraea\Exceptions\SecurityException;

/**
 * Privacy-Preserving Support Diagnostics Bundle Generator.
 *
 * Generates an auditable diagnostic bundle for troubleshooting and support.
 * Strictly redacts passwords, tokens, private keys, cookies, SMTP credentials,
 * email recipients, form submissions, and raw IP addresses.
 *
 * @package Astraea\Diagnostics
 */
final class DiagnosticsBundle {

    private const SENSITIVE_KEYS = [
        'password', 'pass', 'pwd', 'secret', 'token', 'auth', 'key', 'superkey',
        'cookie', 'session', 'salt', 'nonce', 'credential', 'authorization',
        'recipient', 'body', 'content', 'submission', 'envelope', 'ip'
    ];

    /**
     * Build fully sanitized diagnostic data array.
     *
     * @return array<string, mixed>
     */
    public static function build(): array {
        global $wpdb;

        $dbVersion = (isset($wpdb) && $wpdb instanceof \wpdb && !empty($wpdb->dbh)) ? (string)$wpdb->db_version() : 'unknown';

        // 1. Core and Environment Telemetry
        $system = [
            'astraea_version' => Version::VERSION,
            'wp_version'      => $GLOBALS['wp_version'] ?? 'unknown',
            'php_version'     => PHP_VERSION,
            'php_sapi'        => php_sapi_name(),
            'os'              => PHP_OS_FAMILY,
            'web_server'      => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI',
            'memory_limit'    => ini_get('memory_limit'),
            'database_engine' => $dbVersion,
            'extensions'      => [
                'sodium'   => extension_loaded('sodium'),
                'openssl'  => extension_loaded('openssl'),
                'gd'       => extension_loaded('gd'),
                'imagick'  => extension_loaded('imagick'),
                'apcu'     => extension_loaded('apcu'),
                'redis'    => extension_loaded('redis'),
            ],
        ];

        // 2. Module Fabric State
        $modules = [];
        foreach (ModuleRegistry::getAll() as $id => $mod) {
            $desc = $mod->descriptor();
            $state = ModuleRegistry::getState($id);
            $health = $mod->probeHealth();
            $modules[$id] = [
                'name'         => $desc->name,
                'version'      => $desc->version,
                'phase'        => $desc->bootPhase->value,
                'state'        => $state->value,
                'health'       => $health->status,
                'health_label' => $health->label,
            ];
        }

        // 3. Security Probes Summary
        $probes = [
            'zeus'       => SecurityProbeManager::probeZeusCrypto()['status']->value ?? 'UNKNOWN',
            'manifest'   => SecurityProbeManager::probeManifestIntegrity()['status']->value ?? 'UNKNOWN',
            'fileguard'  => SecurityProbeManager::probeFileGuard()['status']->value ?? 'UNKNOWN',
            'vault'      => SecurityProbeManager::probeVault()['status']->value ?? 'UNKNOWN',
        ];

        // 4. Compatibility Flags
        $compat = CompatibilityManager::getFlags();

        $bundle = [
            'export_timestamp'    => time(),
            'export_date_iso8601' => gmdate('c'),
            'system'              => $system,
            'modules'             => $modules,
            'security_probes'     => $probes,
            'compatibility_flags' => $compat,
        ];

        return self::redactRecursive($bundle);
    }

    /**
     * Deep redaction filter ensuring zero secret leakage.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function redactRecursive(mixed $data): mixed {
        if (!is_array($data)) {
            return $data;
        }

        $redacted = [];
        foreach ($data as $key => $val) {
            $keyLower = strtolower((string)$key);

            // Check if key matches sensitive patterns
            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if (str_contains($keyLower, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive && !in_array($keyLower, ['keys', 'security_keys', 'key_id', 'keyring_storage', 'public_key', 'match_type', 'is_key', 'active_flags'], true)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($val)) {
                $redacted[$key] = self::redactRecursive($val);
            } else {
                $redacted[$key] = $val;
            }
        }

        return $redacted;
    }

    public static function renderJsonExport(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'Diagnostics', ['response' => 403]);
        }

        if (function_exists('check_admin_referer')) {
            check_admin_referer('astraea_export_diagnostics', '_astraea_nonce');
        }

        if (class_exists(StepUpAuthService::class) && !StepUpAuthService::isCurrentSessionVerified()) {
            wp_die('Step-Up authentication required prior to diagnostics export.', 'Diagnostics', ['response' => 403]);
        }

        $data = self::build();
        $filename = 'astraea-diagnostics-' . gmdate('Ymd-His') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
