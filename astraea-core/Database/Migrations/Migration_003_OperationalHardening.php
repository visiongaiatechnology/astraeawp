<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Database\Migrations;

use Astraea\Database\Connection;
use Astraea\Database\MigrationInterface;

final class Migration_003_OperationalHardening implements MigrationInterface {
    private bool $createdNotFoundTable = false;

    public function getVersion(): string { return '003_operational_hardening'; }
    public function getDescription(): string { return 'Atomic bounded storage for privacy-preserving 404 telemetry.'; }

    public function up(Connection $connection): void {
        $wpdb = $connection->getWpdb();
        $table = $wpdb->prefix . 'astraea_404_events';
        $this->createdNotFoundTable = $connection->queryVar('SHOW TABLES LIKE %s', [$table]) !== $table;
        $charset = $wpdb->get_charset_collate();
        $connection->execute("CREATE TABLE IF NOT EXISTS {$table} (
            path_hash CHAR(64) NOT NULL,
            path VARCHAR(191) NOT NULL,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            PRIMARY KEY(path_hash),
            KEY ix_last_seen(last_seen)
        ) {$charset}");
    }

    public function down(Connection $connection): void {
        if (!$this->createdNotFoundTable) return;
        $table = $connection->getWpdb()->prefix . 'astraea_404_events';
        $connection->execute("DROP TABLE IF EXISTS {$table}");
    }
}
