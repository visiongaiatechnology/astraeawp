<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Database\Migrations;

use Astraea\Database\Connection;
use Astraea\Database\MigrationInterface;

/**
 * Initial Schema Modernization for AstraeaOS WP.
 *
 * Implements:
 * - Composite index `(post_id, meta_key(191))` on postmeta table.
 * - Composite index `(user_id, meta_key(191))` on usermeta table.
 * - Dedicated secure options table for encrypted secret storage.
 *
 * @package Astraea\Database\Migrations
 */
final class Migration_001_InitialSchema implements MigrationInterface {
    private bool $addedPostmetaIndex = false;
    private bool $addedUsermetaIndex = false;
    private bool $createdSecureOptionsTable = false;

    public function getVersion(): string {
        return '001_initial_schema';
    }

    public function getDescription(): string {
        return 'Meta-table composite indexes (postmeta, usermeta) and secure options storage.';
    }

    public function up(Connection $connection): void {
        $wpdb = $connection->getWpdb();

        // 1. Check & add composite index to postmeta
        $postmetaTable = $wpdb->postmeta;
        $existingPostmetaIndexes = $connection->query("SHOW INDEX FROM {$postmetaTable} WHERE Key_name = 'post_id_meta_key'");
        if (empty($existingPostmetaIndexes)) {
            $connection->execute("ALTER TABLE {$postmetaTable} ADD INDEX post_id_meta_key (post_id, meta_key(191))");
            $this->addedPostmetaIndex = true;
        }

        // 2. Check & add composite index to usermeta
        $usermetaTable = $wpdb->usermeta;
        $existingUsermetaIndexes = $connection->query("SHOW INDEX FROM {$usermetaTable} WHERE Key_name = 'user_id_meta_key'");
        if (empty($existingUsermetaIndexes)) {
            $connection->execute("ALTER TABLE {$usermetaTable} ADD INDEX user_id_meta_key (user_id, meta_key(191))");
            $this->addedUsermetaIndex = true;
        }

        // 3. Create dedicated secure options table
        $charsetCollate = $wpdb->get_charset_collate();
        $secOptionsTable = $wpdb->prefix . 'astraea_sec_options';
        $this->createdSecureOptionsTable = $connection->queryVar('SHOW TABLES LIKE %s', [$secOptionsTable]) !== $secOptionsTable;
        $sql = "CREATE TABLE IF NOT EXISTS {$secOptionsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            option_name VARCHAR(191) NOT NULL,
            option_value LONGTEXT NOT NULL,
            autoload VARCHAR(10) NOT NULL DEFAULT 'no',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_option_name (option_name),
            KEY ix_autoload (autoload)
        ) {$charsetCollate};";

        $connection->execute($sql);
    }

    public function down(Connection $connection): void {
        $wpdb = $connection->getWpdb();

        $postmetaTable = $wpdb->postmeta;
        if ($this->addedPostmetaIndex) $connection->execute("ALTER TABLE {$postmetaTable} DROP INDEX post_id_meta_key");

        $usermetaTable = $wpdb->usermeta;
        if ($this->addedUsermetaIndex) $connection->execute("ALTER TABLE {$usermetaTable} DROP INDEX user_id_meta_key");

        $secOptionsTable = $wpdb->prefix . 'astraea_sec_options';
        if ($this->createdSecureOptionsTable) $connection->execute("DROP TABLE IF EXISTS {$secOptionsTable}");
    }
}
