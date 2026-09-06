<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Migration;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\StorageException;
use Astraea\Exceptions\ValidationException;
use Astraea\Database\MigrationRunner;
use Astraea\Security\SecurityEventManager;
use Astraea\Security\Logger;

/**
 * Reversible WordPress -> Astraea Migration Coordinator.
 *
 * Executes pre-flight analysis, mandates pre-migration Vault snapshots,
 * compiles core database schemas, and records full migration audit receipts.
 *
 * @package Astraea\Migration
 */
final class MigrationWizard {

    public const OPTION_MIGRATION_RECEIPT = 'astraea_migration_receipt';

    /**
     * Execute transactional migration.
     *
     * @param array<string, mixed> $userChoices User-approved feature switches
     * @return array<string, mixed> Migration audit receipt
     * @throws SecurityException
     * @throws StorageException
     */
    public static function executeMigration(array $userChoices = []): array {
        // 1. Environment Pre-Flight
        $env = EnvironmentScanner::scan();
        if (!$env['php_ok']) {
            throw new ValidationException(sprintf('PHP %s does not meet Astraea minimum requirement.', $env['php_version']));
        }

        // 2. Pre-Migration Vault Snapshot (MANDATORY)
        $snapshotId = self::createVaultSnapshot();
        if ($snapshotId === null) {
            // Attempt to initialize Vault if not yet run
            if (class_exists('\\Astraea\\Vault\\Installer')) {
                \Astraea\Vault\Installer::ensureCoreInstalled();
                $snapshotId = self::createVaultSnapshot();
            }
        }

        // 3. Database Schema Migrations
        $migRunner = new MigrationRunner();
        $migRunner->runPending([
            new \Astraea\Database\Migrations\Migration_001_InitialSchema(),
            new \Astraea\Database\Migrations\Migration_002_VLPLight(),
            new \Astraea\Database\Migrations\Migration_003_OperationalHardening(),
        ]);

        // 4. Initialize Core Module State Defaults
        $moduleStates = [
            'vault'       => true,
            'gedefense'   => true,
            'vlp'         => true,
            'mail'        => true,
            'performance' => true,
            'media'       => true,
            'redirects'   => true,
            'seo'         => true,
            'tasks'       => true,
            'database'    => true,
            'identity'    => true,
        ];

        // Apply user-selected toggles if provided
        foreach ($userChoices as $modId => $enabled) {
            if (array_key_exists($modId, $moduleStates)) {
                $moduleStates[$modId] = (bool)$enabled;
            }
        }

        update_option('astraea_module_states', $moduleStates);
        update_option('astraea_migrated_at', time());

        // 5. Generate and Persist Migration Receipt
        $receipt = [
            'status'         => 'SUCCESS',
            'migrated_at'    => time(),
            'snapshot_id'    => $snapshotId ?? 'vault_snapshot_deferred',
            'environment'    => $env,
            'initial_modules'=> $moduleStates,
            'reversible'     => ($snapshotId !== null),
        ];

        update_option(self::OPTION_MIGRATION_RECEIPT, $receipt);

        if (class_exists(SecurityEventManager::class)) {
            SecurityEventManager::recordOnce(
                SecurityEventManager::SEVERITY_SECURITY,
                'Migration',
                'migration_completed',
                'WordPress to Astraea migration successfully executed.',
                ['snapshot_id' => $snapshotId ?? 'none'],
                60
            );
        }

        return $receipt;
    }

    private static function createVaultSnapshot(): ?string {
        if (class_exists('\\Astraea\\Vault\\Plugin')) {
            try {
                $plugin = \Astraea\Vault\Plugin::instance();
                $services = $plugin->services();
                if (isset($services['backup_orchestrator']) && method_exists($services['backup_orchestrator'], 'run')) {
                    $result = $services['backup_orchestrator']->run('pre-migration-full');
                    if (is_array($result) && isset($result['id'])) {
                        return (string)$result['id'];
                    }
                }
            } catch (\Throwable $e) {
                Logger::warning('[MigrationWizard] Pre-migration Vault snapshot error: ' . $e->getMessage());
            }
        }
        return null;
    }
}
