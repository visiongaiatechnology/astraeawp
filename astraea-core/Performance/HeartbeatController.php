<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Performance;

/**
 * Adaptive Heartbeat Frequency Controller.
 *
 * Prevents runaway admin polling cycles and disables unnecessary frontend
 * heartbeat execution without breaking post-locking semantics.
 *
 * @package Astraea\Performance
 */
final class HeartbeatController {

    public static function init(): void {
        add_filter('heartbeat_settings', [self::class, 'tuneHeartbeatSettings']);
        add_action('init', [self::class, 'deregisterFrontendHeartbeat'], 1);
    }

    /**
     * Tune heartbeat interval to 60 seconds (default is 15-30 seconds).
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function tuneHeartbeatSettings(array $settings): array {
        $settings['interval'] = 60;
        return $settings;
    }

    /**
     * Disable heartbeat script on non-admin frontend screens.
     */
    public static function deregisterFrontendHeartbeat(): void {
        if (!is_admin()) {
            wp_deregister_script('heartbeat');
        }
    }
}
