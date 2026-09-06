<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Redirects;

/**
 * Automatic Post Slug Change Redirect Tracker.
 *
 * Monitors post publication and permalink changes, automatically creating 301
 * permanent redirects to prevent broken search engine links and 404 errors.
 *
 * @package Astraea\Redirects
 */
final class SlugChangeWatcher {

    public static function init(): void {
        add_action('post_updated', [self::class, 'handlePostUpdated'], 10, 3);
    }

    /**
     * @param int $postId
     * @param \WP_Post $postAfter
     * @param \WP_Post $postBefore
     */
    public static function handlePostUpdated(int $postId, \WP_Post $postAfter, \WP_Post $postBefore): void {
        // Only monitor published posts with changed post_name (slug)
        if ($postAfter->post_status !== 'publish' || $postBefore->post_status !== 'publish') {
            return;
        }

        if ($postAfter->post_name === $postBefore->post_name || $postBefore->post_name === '') {
            return;
        }

        $oldUrl = get_permalink($postBefore);
        $newUrl = get_permalink($postAfter);

        if (!is_string($oldUrl) || !is_string($newUrl) || $oldUrl === $newUrl) {
            return;
        }

        $oldPath = parse_url($oldUrl, PHP_URL_PATH);
        $newPath = parse_url($newUrl, PHP_URL_PATH);

        if (!is_string($oldPath) || !is_string($newPath) || $oldPath === $newPath) {
            return;
        }

        // Register 301 redirect from old path to new path
        $rule = new RedirectRule(
            id: 'slug-' . $postId . '-' . time(),
            source: $oldPath,
            target: $newPath,
            code: 301,
            matchType: RedirectRule::MATCH_EXACT,
            hits: 0,
            created: time(),
            active: true
        );

        RedirectEngine::saveRule($rule);
    }
}
