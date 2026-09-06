<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Database;

/**
 * AstraeaOS Versioned Database Migration Runner.
 *
 * Discovers, validates, and executes core schema migrations.
 * Maintains an idempotent record of applied versions in `{$prefix}astraea_migrations`
 * and synchronizes the global `astraea_db_version` option.
 *
 * @package Astraea\Database
 */
final class MigrationRunner {

    public const ASTRAEA_DB_VERSION = '1.2.0';

    private Connection $connection;

    public function __construct(?Connection $connection = null) {
        $this->connection = $connection ?? Connection::getInstance();
    }

    /**
     * Ensure the migrations tracking table exists.
     */
    public function ensureTrackingTable(): void {
        $wpdb = $this->connection->getWpdb();
        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'astraea_migrations';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            version VARCHAR(100) NOT NULL,
            description VARCHAR(255) NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (version)
        ) {$charsetCollate};";

        $wpdb->query($sql);
    }

    /**
     * Get list of already applied migration versions.
     *
     * @return string[]
     */
    public function getAppliedVersions(): array {
        $this->ensureTrackingTable();
        $table = $this->connection->getWpdb()->prefix . 'astraea_migrations';
        $rows = $this->connection->query("SELECT version FROM {$table} ORDER BY version ASC");
        return array_column($rows, 'version');
    }

    /**
     * Run all pending migrations in deterministic order.
     *
     * @param MigrationInterface[] $migrations
     * @return array<string, string> Map of version => status ('applied' | 'skipped')
     */
    public function runPending(array $migrations, bool $compensateOnFailure = true): array {
        $this->ensureTrackingTable();
        $applied = $this->getAppliedVersions();
        $results = [];
        $appliedNow = [];
        $current = null;

        try {
            foreach ($migrations as $migration) {
                $version = $migration->getVersion();
                if (in_array($version, $applied, true)) {
                    $results[$version] = 'skipped';
                    continue;
                }
                $current = $migration;

                // MySQL DDL may auto-commit. Migration objects therefore retain
                // their own change-set so compensation can reverse partial DDL.
                $this->connection->transactional(function () use ($migration): void {
                    $migration->up($this->connection);

                    $table = $this->connection->getWpdb()->prefix . 'astraea_migrations';
                    $wpdb = $this->connection->getWpdb();
                    if ($wpdb->insert($table, [
                        'version'     => $migration->getVersion(),
                        'description' => $migration->getDescription(),
                        'applied_at'  => gmdate('Y-m-d H:i:s'),
                    ], ['%s', '%s', '%s']) === false) {
                        throw new \Astraea\Exceptions\StorageException('Migration tracking persistence failed.');
                    }
                });

                $results[$version] = 'applied';
                $appliedNow[] = $version;
                $current = null;
            }
        } catch (\Throwable $failure) {
            if ($compensateOnFailure) {
                $versions = $appliedNow;
                if ($current instanceof MigrationInterface) $versions[] = $current->getVersion();
                try {
                    $this->rollbackApplied($migrations, $versions);
                } catch (\Throwable $rollbackFailure) {
                    throw new \Astraea\Exceptions\StorageException('Migration compensation failed; manual recovery is required.', 0, $rollbackFailure);
                }
            }
            throw $failure;
        }

        if (function_exists('update_option')) {
            update_option('astraea_db_version', self::ASTRAEA_DB_VERSION, false);
        }
        return $results;
    }

    /**
     * Reverse only migrations applied by the current update transaction.
     * @param MigrationInterface[] $migrations
     * @param string[] $versions
     */
    public function rollbackApplied(array $migrations, array $versions): void {
        $map = [];
        foreach ($migrations as $migration) $map[$migration->getVersion()] = $migration;
        $table = $this->connection->getWpdb()->prefix . 'astraea_migrations';

        foreach (array_reverse(array_values(array_unique($versions))) as $version) {
            if (!isset($map[$version])) continue;
            $map[$version]->down($this->connection);
            $this->connection->execute("DELETE FROM {$table} WHERE version = %s", [$version]);
        }

        if (function_exists('delete_option')) delete_option('astraea_db_version');
    }
}
