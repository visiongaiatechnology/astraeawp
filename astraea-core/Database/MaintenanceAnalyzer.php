<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Database;

/**
 * Dry-Run Database Maintenance & Storage Analyzer.
 *
 * Inspects obsolete records, orphan metadata, expired transients, and table
 * fragmentation in read-only analysis mode before any destructive mutation.
 *
 * @package Astraea\Database
 */
final class MaintenanceAnalyzer {

    /**
     * Perform dry-run audit across all cleanup categories.
     *
     * @return array<string, array{label: string, count: int, estimated_bytes: int}>
     */
    public static function analyze(): array {
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof \wpdb) || empty($wpdb->dbh)) {
            return [];
        }

        $now = time();

        // 1. Post Revisions
        $revisionsCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE `post_type` = 'revision'");
        $revisionsSize  = (int)$wpdb->get_var("SELECT SUM(LENGTH(`post_content`)) FROM `{$wpdb->posts}` WHERE `post_type` = 'revision'");

        // 2. Trashed Posts
        $trashPostsCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE `post_status` = 'trash'");
        $trashPostsSize  = (int)$wpdb->get_var("SELECT SUM(LENGTH(`post_content`)) FROM `{$wpdb->posts}` WHERE `post_status` = 'trash'");

        // 3. Spam & Trashed Comments
        $spamCommentsCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->comments}` WHERE `comment_approved` = 'spam' OR `comment_approved` = 'trash'");
        $spamCommentsSize  = (int)$wpdb->get_var("SELECT SUM(LENGTH(`comment_content`)) FROM `{$wpdb->comments}` WHERE `comment_approved` = 'spam' OR `comment_approved` = 'trash'");

        // 4. Expired Transients
        $optionsTable = $wpdb->options;
        $expiredTransients = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$optionsTable}` WHERE `option_name` LIKE %s AND `option_value` < %d",
            $wpdb->esc_like('_transient_timeout_') . '%',
            $now
        ));

        // 5. Orphaned Post Meta
        $orphanPostMeta = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE `post_id` NOT IN (SELECT `ID` FROM `{$wpdb->posts}`)");

        // 6. Orphaned Comment Meta
        $orphanCommentMeta = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->commentmeta}` WHERE `comment_id` NOT IN (SELECT `comment_ID` FROM `{$wpdb->comments}`)");

        return [
            'revisions' => [
                'label'           => 'Post Revisions',
                'count'           => $revisionsCount,
                'estimated_bytes' => $revisionsSize,
            ],
            'trash_posts' => [
                'label'           => 'Trashed Posts and Pages',
                'count'           => $trashPostsCount,
                'estimated_bytes' => $trashPostsSize,
            ],
            'spam_comments' => [
                'label'           => 'Spam & Trashed Comments',
                'count'           => $spamCommentsCount,
                'estimated_bytes' => $spamCommentsSize,
            ],
            'expired_transients' => [
                'label'           => 'Expired Cache Transients',
                'count'           => $expiredTransients,
                'estimated_bytes' => $expiredTransients * 512, // Estimate ~512B per transient
            ],
            'orphan_postmeta' => [
                'label'           => 'Orphaned Post Metadata',
                'count'           => $orphanPostMeta,
                'estimated_bytes' => $orphanPostMeta * 128,
            ],
            'orphan_commentmeta' => [
                'label'           => 'Orphaned Comment Metadata',
                'count'           => $orphanCommentMeta,
                'estimated_bytes' => $orphanCommentMeta * 128,
            ],
        ];
    }
}
