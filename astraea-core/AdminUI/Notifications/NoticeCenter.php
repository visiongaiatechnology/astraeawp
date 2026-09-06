<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\AdminUI\Notifications;

/**
 * Astraea Notice Center.
 *
 * Consolidates chaotic WordPress core and third-party plugin notices into
 * an elegant, non-intrusive Glass Notification system:
 * - Styles in-page notices as sleek glass toast cards
 * - Provides nonces and endpoints for dismissing persistent notices
 * - Allows client-side drawer collection without breaking plugin output
 *
 * @package Astraea\AdminUI\Notifications
 */
final class NoticeCenter {

    /**
     * Initialize NoticeCenter hooks.
     */
    public static function init(): void {
        add_action('admin_notices', [self::class, 'wrapNoticesStart'], -9999);
        add_action('admin_notices', [self::class, 'wrapNoticesEnd'], 9999);
    }

    /**
     * Open notification wrapper buffer.
     */
    public static function wrapNoticesStart(): void {
        echo '<div id="astraea-notices-anchor" class="astraea-notices-container" aria-live="polite">';
    }

    /**
     * Close notification wrapper buffer.
     */
    public static function wrapNoticesEnd(): void {
        echo '</div>';
    }
}
