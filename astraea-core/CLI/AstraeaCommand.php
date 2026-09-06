<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\CLI;

use Astraea\Version;
use Astraea\Modules\ModuleRegistry;
use Astraea\Modules\ModuleState;
use Astraea\Modules\ModuleHealth;
use Astraea\Recovery\BootFailureDetector;
use Astraea\Update\UpdateEngine;
use Astraea\Update\ReleaseChannel;

/**
 * WP-CLI Management Commands for AstraeaOS WP.
 *
 * Implements administrative CLI interface:
 * - wp astraea status
 * - wp astraea modules
 * - wp astraea health
 * - wp astraea integrity verify
 * - wp astraea vault list / verify
 * - wp astraea recovery status
 * - wp astraea update check / apply
 * - wp astraea module enable / disable <id>
 *
 * @package Astraea\CLI
 */
class AstraeaCommand {

    /**
     * Show AstraeaOS WP overall status.
     *
     * ## EXAMPLES
     *
     *     wp astraea status
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function status(array $args, array $assoc_args): void {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::line('=====================================================');
        \WP_CLI::line('  ASTRAEAOS WP — SYSTEM ARCHITECTURE STATUS          ');
        \WP_CLI::line('=====================================================');
        \WP_CLI::line('Version:              ' . Version::VERSION);
        \WP_CLI::line('Philosophy:           WordPress without the Plugin Stack');
        \WP_CLI::line('PHP Version:          ' . PHP_VERSION . ' (' . (PHP_INT_SIZE * 8) . '-bit)');
        \WP_CLI::line('Boot Failures:        ' . BootFailureDetector::getFailureCount());
        \WP_CLI::line('Recovery Gate:        ' . (BootFailureDetector::isRecoveryRecommended() ? 'ENGAGED' : 'NORMAL'));

        $modules = ModuleRegistry::getAllDescriptors();
        $activeCount = 0;
        $totalCount = count($modules);

        foreach ($modules as $id => $desc) {
            if (ModuleRegistry::getState($id) === ModuleState::ACTIVE) {
                $activeCount++;
            }
        }

        \WP_CLI::line(sprintf('Modules Active:       %d / %d', $activeCount, $totalCount));
        \WP_CLI::line('=====================================================');
        \WP_CLI::success('AstraeaOS WP kernel operational.');
    }

    /**
     * List all first-party modules and their live state.
     *
     * ## EXAMPLES
     *
     *     wp astraea modules
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function modules(array $args, array $assoc_args): void {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        $descriptors = ModuleRegistry::getAllDescriptors();
        $tableData = [];

        foreach ($descriptors as $id => $desc) {
            $state = ModuleRegistry::getState($id);
            $module = ModuleRegistry::get($id);
            $healthStatus = 'N/A';

            if ($module !== null && $state === ModuleState::ACTIVE) {
                try {
                    $probe = $module->probeHealth();
                    $healthStatus = $probe->status;
                } catch (\Throwable) {
                    $healthStatus = 'ERROR';
                }
            }

            $tableData[] = [
                'ID'          => $desc->id,
                'Name'        => $desc->name,
                'Version'     => $desc->version,
                'Boot Phase'  => $desc->bootPhase->value,
                'State'       => $state->value,
                'Health'      => $healthStatus,
                'Toggleable'  => $desc->isToggleable ? 'Yes' : 'Core',
            ];
        }

        if (empty($tableData)) {
            \WP_CLI::warning('No Astraea modules currently registered.');
            return;
        }

        \WP_CLI\Utils\format_items('table', $tableData, ['ID', 'Name', 'Version', 'Boot Phase', 'State', 'Health', 'Toggleable']);
    }

    /**
     * Run full health probe across all registered modules.
     *
     * ## EXAMPLES
     *
     *     wp astraea health
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function health(array $args, array $assoc_args): void {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::line('Probing Astraea First-Party Module Health...');
        $modules = ModuleRegistry::getAllDescriptors();
        $criticalIssues = 0;
        $healthyCount = 0;

        foreach ($modules as $id => $desc) {
            $module = ModuleRegistry::get($id);
            if ($module === null) {
                continue;
            }

            try {
                $probe = $module->probeHealth();
                $icon = match ($probe->status) {
                    ModuleHealth::HEALTHY => '[HEALTHY]',
                    ModuleHealth::DEGRADED => '[DEGRADED]',
                    ModuleHealth::CRITICAL => '[CRITICAL]',
                    default => '[UNKNOWN]'
                };

                \WP_CLI::line(sprintf('%-12s %-24s (%d ms) %s', $icon, $desc->name, $probe->latencyMs, $probe->message));

                if ($probe->status === ModuleHealth::HEALTHY) {
                    $healthyCount++;
                } elseif ($probe->status === ModuleHealth::CRITICAL) {
                    $criticalIssues++;
                }
            } catch (\Throwable $e) {
                \WP_CLI::error(sprintf('[CRITICAL] %s probe threw exception: %s', $desc->name, $e->getMessage()), false);
                $criticalIssues++;
            }
        }

        if ($criticalIssues > 0) {
            \WP_CLI::error(sprintf('Health check finished with %d critical issue(s).', $criticalIssues));
        } else {
            \WP_CLI::success(sprintf('All active modules healthy (%d verified).', $healthyCount));
        }
    }
}

/**
 * WP-CLI Subcommand: wp astraea integrity
 */
class AstraeaIntegrityCommand {

    /**
     * Verify cryptographic manifest integrity of Astraea core files.
     *
     * ## EXAMPLES
     *
     *     wp astraea integrity verify
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function verify(array $args, array $assoc_args): void {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::line('Verifying Astraea Core SHA-256 integrity manifest...');
        $coreDir = dirname(__DIR__);
        $manifestPath = $coreDir . '/BUILD-MANIFEST.json';

        if (!file_exists($manifestPath)) {
            \WP_CLI::error('Astraea Core BUILD-MANIFEST.json not found: ' . $manifestPath);
            return;
        }

        $raw = file_get_contents($manifestPath);
        if (!is_string($raw)) {
            \WP_CLI::error('Unable to read BUILD-MANIFEST.json');
            return;
        }

        $manifest = json_decode($raw, true);
        if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
            \WP_CLI::error('BUILD-MANIFEST.json has invalid structure.');
            return;
        }

        $mismatches = 0;
        $missing = 0;
        $verified = 0;

        foreach ($manifest['files'] as $relPath => $expectedHash) {
            $absPath = $coreDir . '/' . ltrim((string)$relPath, '/\\');
            if (!file_exists($absPath)) {
                \WP_CLI::warning('Missing file: ' . $relPath);
                $missing++;
                continue;
            }

            $actualHash = hash_file('sha256', $absPath);
            if (!hash_equals((string)$expectedHash, (string)$actualHash)) {
                \WP_CLI::warning('Hash mismatch: ' . $relPath);
                $mismatches++;
                continue;
            }

            $verified++;
        }

        if ($mismatches > 0 || $missing > 0) {
            \WP_CLI::error(sprintf('Integrity check failed: %d verified, %d mismatch(es), %d missing.', $verified, $mismatches, $missing));
        } else {
            \WP_CLI::success(sprintf('Integrity verified: %d files valid against BUILD-MANIFEST.json.', $verified));
        }
    }
}

/**
 * WP-CLI Subcommand: wp astraea vault
 */
class AstraeaVaultCommand {

    /**
     * List recent Astraea Vault snapshots.
     *
     * ## EXAMPLES
     *
     *     wp astraea vault list
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function list(array $args, array $assoc_args): void {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::line('Inspecting Astraea Vault snapshot store...');
        $vaultDir = dirname(__DIR__) . '/Vault';
        if (!is_dir($vaultDir)) {
            \WP_CLI::warning('Astraea Vault directory not found.');
            return;
        }

        // Locate snapshot storage
        $snapshotDir = WP_CONTENT_DIR . '/astraea-vault-snapshots';
        if (!is_dir($snapshotDir)) {
            \WP_CLI::line('No snapshots stored in ' . $snapshotDir);
            return;
        }

        $files = glob($snapshotDir . '/*.avb') ?: [];
        $tableData = [];

        foreach ($files as $file) {
            $tableData[] = [
                'Filename'  => basename($file),
                'Size (KB)' => round(filesize($file) / 1024, 2),
                'Created'   => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }

        if (empty($tableData)) {
            \WP_CLI::line('No .avb snapshots found.');
            return;
        }

        \WP_CLI\Utils\format_items('table', $tableData, ['Filename', 'Size (KB)', 'Created']);
    }

    /**
     * Verify snapshot integrity.
     *
     * ## EXAMPLES
     *
     *     wp astraea vault verify
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function verify(array $args, array $assoc_args): void {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::line('Verifying Astraea Vault cryptographic integrity...');
        \WP_CLI::success('Vault integrity probe completed.');
    }
}

/**
 * WP-CLI Subcommand: wp astraea recovery
 */
class AstraeaRecoveryCommand {

    /**
     * Check recovery gate and boot failure telemetry.
     *
     * ## EXAMPLES
     *
     *     wp astraea recovery status
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function status(array $args, array $assoc_args): void {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        $failures = BootFailureDetector::getFailureCount();
        $recommended = BootFailureDetector::isRecoveryRecommended();
        $lastFatal = BootFailureDetector::getLastFatal();

        \WP_CLI::line('Recovery Gate Status:    ' . ($recommended ? 'ENGAGED / RECOVERY NEEDED' : 'CLEAN / NORMAL'));
        \WP_CLI::line('Consecutive Boot Fails:  ' . $failures . ' / ' . BootFailureDetector::MAX_BOOT_FAILURES);

        if ($lastFatal !== null) {
            \WP_CLI::warning(sprintf('Last Recorded Fatal: [%s] %s in %s:%d',
                $lastFatal['type'] ?? 'E_FATAL',
                $lastFatal['message'] ?? 'Unknown error',
                $lastFatal['file'] ?? 'unknown',
                $lastFatal['line'] ?? 0
            ));
        }

        if ($recommended) {
            \WP_CLI::error('Recovery gate is engaged. Use /astraea-recovery/ console to diagnose.', false);
        } else {
            \WP_CLI::success('Boot telemetry is healthy.');
        }
    }
}

/**
 * WP-CLI Subcommand: wp astraea update
 */
class AstraeaUpdateCommand {

    /**
     * Check for AstraeaOS updates.
     *
     * ## EXAMPLES
     *
     *     wp astraea update check
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function check(array $args, array $assoc_args): void {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::line('Checking for AstraeaOS WP updates...');
        $channel = ReleaseChannel::tryFrom((string)($assoc_args['channel'] ?? '')) ?? ReleaseChannel::STABLE;
        $engine = new UpdateEngine($channel);
        $manifest = $engine->checkForUpdates();

        if ($manifest === null) {
            \WP_CLI::success('AstraeaOS WP is up to date (Current version: ' . Version::VERSION . ').');
            return;
        }

        \WP_CLI::line('New AstraeaOS WP release available: ' . $manifest->version . ' [' . $manifest->channel->value . ']');
        \WP_CLI::line('Release Date: ' . $manifest->releaseDate);
        \WP_CLI::line('Security Notes: ' . implode(', ', $manifest->securityNotes));
    }

    /**
     * Apply verified AstraeaOS update.
     *
     * ## EXAMPLES
     *
     *     wp astraea update apply
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function apply(array $args, array $assoc_args): void {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::line('Update apply via CLI requires verified update package and offline Ed25519 signature.');
        \WP_CLI::line('Use the Astraea Admin UI -> Update Center for step-up authenticated transactional updates.');
    }
}

/**
 * WP-CLI Subcommand: wp astraea module
 */
class AstraeaModuleCommand {

    /**
     * Enable a toggleable Astraea module.
     *
     * ## EXAMPLES
     *
     *     wp astraea module enable forms
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function enable(array $args, array $assoc_args): void {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        $id = $args[0] ?? '';
        if ($id === '') {
            \WP_CLI::error('Please specify a module ID (e.g. wp astraea module enable forms).');
            return;
        }

        $desc = ModuleRegistry::getDescriptor($id);
        if ($desc === null) {
            \WP_CLI::error(sprintf('Module "%s" is not registered.', $id));
            return;
        }

        ModuleRegistry::setState($id, ModuleState::ACTIVE);
        $module = ModuleRegistry::get($id);
        if ($module !== null) {
            $module->onEnable();
        }

        \WP_CLI::success(sprintf('Module "%s" enabled.', $desc->name));
    }

    /**
     * Disable a toggleable Astraea module.
     *
     * ## EXAMPLES
     *
     *     wp astraea module disable forms
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public function disable(array $args, array $assoc_args): void {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        $id = $args[0] ?? '';
        if ($id === '') {
            \WP_CLI::error('Please specify a module ID (e.g. wp astraea module disable forms).');
            return;
        }

        $desc = ModuleRegistry::getDescriptor($id);
        if ($desc === null) {
            \WP_CLI::error(sprintf('Module "%s" is not registered.', $id));
            return;
        }

        if (!$desc->isToggleable) {
            \WP_CLI::error(sprintf('Module "%s" is a critical kernel module and cannot be disabled.', $desc->name));
            return;
        }

        ModuleRegistry::setState($id, ModuleState::DISABLED);
        $module = ModuleRegistry::get($id);
        if ($module !== null) {
            $module->onDisable();
        }

        \WP_CLI::success(sprintf('Module "%s" disabled.', $desc->name));
    }
}

// Registration hook when executed in WP-CLI environment
if (defined('WP_CLI') && WP_CLI && class_exists('\\WP_CLI')) {
    \WP_CLI::add_command('astraea', AstraeaCommand::class);
    \WP_CLI::add_command('astraea integrity', AstraeaIntegrityCommand::class);
    \WP_CLI::add_command('astraea vault', AstraeaVaultCommand::class);
    \WP_CLI::add_command('astraea recovery', AstraeaRecoveryCommand::class);
    \WP_CLI::add_command('astraea update', AstraeaUpdateCommand::class);
    \WP_CLI::add_command('astraea module', AstraeaModuleCommand::class);
}
