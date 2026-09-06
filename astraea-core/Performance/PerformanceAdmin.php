<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Performance;

/**
 * Performance Center Administration Dashboard.
 *
 * Real-time telemetry HUD displaying measured TTFB, memory footprint,
 * autoload option bytes, and page cache state without mock numbers.
 *
 * @package Astraea\Performance
 */
final class PerformanceAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_astraea_purge_page_cache', [self::class, 'handlePurgeCache']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'index.php',
            'Astraea Performance Engine',
            'Performance',
            'manage_options',
            'astraea-performance',
            [self::class, 'renderScreen']
        );
    }

    public static function handlePurgeCache(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'Performance', ['response' => 403]);
        }

        check_admin_referer('astraea_perf_action', '_astraea_nonce');

        $purged = PageCache::purgeAll();
        wp_safe_redirect(admin_url('admin.php?page=astraea-performance&purged=' . $purged));
        exit;
    }

    public static function renderScreen(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', 'Performance', ['response' => 403]);
        }

        $objCache = ObjectCacheDetector::detect();
        $autoload = DatabaseProfiler::profileAutoload();
        $tables = DatabaseProfiler::profileTables();
        $cacheStats = PageCache::getStats();
        $peakMemMb = round(memory_get_peak_usage(true) / (1024 * 1024), 2);
        $nonce = wp_create_nonce('astraea_perf_action');

        global $wpdb;
        $dbVer = (isset($wpdb) && $wpdb instanceof \wpdb && !empty($wpdb->dbh)) ? (string)$wpdb->db_version() : 'unknown';
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 1000px; margin: 24px auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <div>
                    <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea Performance Center</h1>
                    <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">Zero-bloat telemetry and native caching layers — measurement before optimization.</p>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="astraea_purge_page_cache">
                    <input type="hidden" name="_astraea_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <button type="submit" class="button" style="background: rgba(14, 165, 233, 0.2); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.4); font-size: 13px; padding: 6px 16px; border-radius: 6px; cursor: pointer;">
                        Purge Page Cache (<?php echo (int)$cacheStats['file_count']; ?> files)
                    </button>
                </form>
            </div>

            <!-- Real-time Telemetry Metrics Grid -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 18px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Peak Memory</div>
                    <div style="font-size: 24px; font-weight: 700; color: #38bdf8; margin: 6px 0 2px; font-family: monospace;"><?php echo esc_html((string)$peakMemMb); ?> MB</div>
                    <div style="font-size: 11px; color: #64748b;">PHP Limit: <?php echo esc_html(ini_get('memory_limit') ?: 'N/A'); ?></div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 18px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Autoload Payload</div>
                    <div style="font-size: 24px; font-weight: 700; color: <?php echo $autoload['total_size_bytes'] > 800000 ? '#f87171' : '#4ade80'; ?>; margin: 6px 0 2px; font-family: monospace;">
                        <?php echo esc_html((string)round($autoload['total_size_bytes'] / 1024, 1)); ?> KB
                    </div>
                    <div style="font-size: 11px; color: #64748b;"><?php echo (int)$autoload['total_options']; ?> autoloaded options</div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 18px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Disk Page Cache</div>
                    <div style="font-size: 24px; font-weight: 700; color: #38bdf8; margin: 6px 0 2px; font-family: monospace;">
                        <?php echo (int)$cacheStats['file_count']; ?>
                    </div>
                    <div style="font-size: 11px; color: #64748b;"><?php echo esc_html((string)round($cacheStats['size_bytes'] / 1024, 1)); ?> KB cached</div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 18px;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Object Cache</div>
                    <div style="font-size: 14px; font-weight: 700; color: <?php echo $objCache['persistent'] ? '#4ade80' : '#facc15'; ?>; margin: 10px 0 6px;">
                        <?php echo esc_html($objCache['status']); ?>
                    </div>
                    <div style="font-size: 11px; color: #64748b;"><?php echo esc_html($objCache['backend']); ?></div>
                </div>
            </div>

            <!-- Database & Autoload Breakdown Table -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 24px; margin-bottom: 24px;">
                <h3 style="color: #f8fafc; margin: 0 0 16px; font-size: 16px;">Top Autoloaded Options</h3>
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; color: #cbd5e1;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); color: #94a3b8; font-size: 11px; text-transform: uppercase;">
                            <th style="padding: 8px 12px;">Option Name</th>
                            <th style="padding: 8px 12px; text-align: right;">Size (Bytes)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($autoload['largest_options'])): ?>
                            <tr><td colspan="2" style="padding: 12px; color: #64748b;">No autoload data available.</td></tr>
                        <?php else: ?>
                            <?php foreach ($autoload['largest_options'] as $opt): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                    <td style="padding: 10px 12px; font-family: monospace; font-size: 12px; color: #f1f5f9;">
                                        <?php echo htmlspecialchars($opt['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding: 10px 12px; text-align: right; font-family: monospace;">
                                        <?php echo number_format($opt['size_bytes']); ?> B
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
