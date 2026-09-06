<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Database\Migrations;

use Astraea\Database\Connection;
use Astraea\Database\MigrationInterface;

final class Migration_002_VLPLight implements MigrationInterface {
    private bool $createdTable = false;
    public function getVersion(): string { return '002_vlp_light'; }
    public function getDescription(): string { return 'VLP Light sovereign consent analytics event store.'; }
    public function up(Connection $connection): void {
        $wpdb=$connection->getWpdb(); $table=$wpdb->prefix.'astraea_vlp_dattrack_events'; $charset=$wpdb->get_charset_collate();
        $this->createdTable = $connection->queryVar('SHOW TABLES LIKE %s', [$table]) !== $table;
        $connection->execute("CREATE TABLE IF NOT EXISTS {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,event_date DATE NOT NULL,visitor_day CHAR(64) NOT NULL,payload LONGTEXT NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(id),KEY ix_created_at(created_at),KEY ix_event_visitor(event_date,visitor_day)) {$charset}");
    }
    public function down(Connection $connection): void { if (!$this->createdTable) return; $table=$connection->getWpdb()->prefix.'astraea_vlp_dattrack_events'; $connection->execute("DROP TABLE IF EXISTS {$table}"); }
}
