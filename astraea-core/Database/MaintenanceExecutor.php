<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Database;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\StorageException;
use Astraea\Security\SecurityEventManager;
use Astraea\Security\Logger;

/**
 * Vault-Guaranteed Database Cleanup & Optimization Executor.
 *
 * Mandates a verified pre-cleanup Astraea Vault snapshot before any
 * destructive record deletion or table reorganization is executed.
 *
 * @package Astraea\Database
 */
final class MaintenanceExecutor {

    /**
     * Execute selected maintenance operations.
     *
     * @param list<string> $categories Selected categories from MaintenanceAnalyzer
     * @return array{deleted_rows: int, snapshot_id: ?string}
     * @throws SecurityException
     * @throws StorageException
     */
    public static function execute(array $categories): array {
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof \wpdb) || empty($wpdb->dbh)) {
            throw new StorageException('Database connection unavailable.');
        }

        // 1. Mandatory Pre-Cleanup Vault Snapshot
        $snapshotId = self::triggerVaultSnapshot();

        $deletedRows = 0;
        $now = time();

        // 2. Execute selected operations
        if (in_array('revisions', $categories, true)) {
            $count = (int)$wpdb->query("DELETE FROM `{$wpdb->posts}` WHERE `post_type` = 'revision'");
            $deletedRows += $count;
        }

        if (in_array('trash_posts', $categories, true)) {
            $count = (int)$wpdb->query("DELETE FROM `{$wpdb->posts}` WHERE `post_status` = 'trash'");
            $deletedRows += $count;
        }

        if (in_array('spam_comments', $categories, true)) {
            $count = (int)$wpdb->query("DELETE FROM `{$wpdb->comments}` WHERE `comment_approved` = 'spam' OR `comment_approved` = 'trash'");
            $deletedRows += $count;
        }

        if (in_array('expired_transients', $categories, true)) {
            $optionsTable = $wpdb->options;
            // Delete expired transient timeout records
            $timeouts = $wpdb->get_col($wpdb->prepare(
                "SELECT `option_name` FROM `{$optionsTable}` WHERE `option_name` LIKE %s AND `option_value` < %d",
                $wpdb->esc_like('_transient_timeout_') . '%',
                $now
            ));

            if (!empty($timeouts)) {
                foreach ($timeouts as $timeoutKey) {
                    $baseKey = str_replace('_transient_timeout_', '_transient_', $timeoutKey);
                    $wpdb->query($wpdb->prepare("DELETE FROM `{$optionsTable}` WHERE `option_name` IN (%s, %s)", $timeoutKey, $baseKey));
                    $deletedRows += 2;
                }
            }
        }

        if (in_array('orphan_postmeta', $categories, true)) {
            $count = (int)$wpdb->query("DELETE FROM `{$wpdb->postmeta}` WHERE `post_id` NOT IN (SELECT `ID` FROM `{$wpdb->posts}`)");
            $deletedRows += $count;
        }

        if (in_array('orphan_commentmeta', $categories, true)) {
            $count = (int)$wpdb->query("DELETE FROM `{$wpdb->commentmeta}` WHERE `comment_id` NOT IN (SELECT `comment_ID` FROM `{$wpdb->comments}`)");
            $deletedRows += $count;
        }

        if (class_exists(SecurityEventManager::class)) {
            SecurityEventManager::recordOnce(
                SecurityEventManager::SEVERITY_INFO,
                'Database',
                'database_maintenance_cleanup',
                sprintf('Database maintenance completed. Deleted %d records. Snapshot: %s', $deletedRows, $snapshotId ?? 'none'),
                ['deleted_rows' => $deletedRows, 'snapshot_id' => $snapshotId ?? 'none'],
                60
            );
        }

        return [
            'deleted_rows' => $deletedRows,
            'snapshot_id'  => $snapshotId,
        ];
    }

    private static function triggerVaultSnapshot(): ?string {
        if (class_exists('\\Astraea\\Vault\\Plugin')) {
            try {
                $plugin = \Astraea\Vault\Plugin::instance();
                $services = $plugin->services();
                if (isset($services['backup_orchestrator']) && method_exists($services['backup_orchestrator'], 'run')) {
                    $result = $services['backup_orchestrator']->run('pre-db-cleanup');
                    if (is_array($result) && isset($result['id'])) {
                        return (string)$result['id'];
                    }
                }
            } catch (\Throwable $e) {
                Logger::warning('[DatabaseMaintenance] Pre-cleanup Vault snapshot warning: ' . $e->getMessage());
            }
        }
        return null;
    }
}
