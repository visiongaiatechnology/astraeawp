<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Media;

/**
 * Media Engine Administration & Capabilities Screen.
 *
 * @package Astraea\Media
 */
final class MediaAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'upload.php',
            'Astraea Media Engine',
            'Media Security',
            'upload_files',
            'astraea-media',
            [self::class, 'renderScreen']
        );
    }

    public static function renderScreen(): void {
        if (!current_user_can('upload_files')) {
            wp_die('Access denied.', 'Media', ['response' => 403]);
        }

        $matrix = FormatCapabilities::matrix();
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 900px; margin: 24px auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea Media Engine</h1>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">Polyglot-immune upload processing, strict MIME cross-checking, and server codec detection.</p>
            </div>

            <!-- Server Codec Capability Matrix -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 24px; margin-bottom: 24px;">
                <h3 style="color: #f8fafc; margin: 0 0 16px; font-size: 16px;">Host Server Codec Matrix</h3>
                <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px;">
                    <?php foreach ($matrix as $format => $status): ?>
                        <div style="background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 8px; padding: 14px; text-align: center;">
                            <div style="font-size: 13px; font-weight: 700; color: #f1f5f9; text-transform: uppercase; margin-bottom: 6px;"><?php echo esc_html($format); ?></div>
                            <span style="font-size: 10px; font-weight: 700; padding: 3px 6px; border-radius: 4px; <?php echo $status === FormatCapabilities::STATUS_SUPPORTED ? 'background: rgba(34, 197, 94, 0.15); color: #4ade80;' : 'background: rgba(148, 163, 184, 0.15); color: #94a3b8;'; ?>">
                                <?php echo esc_html($status); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Active Defense Policies -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 24px;">
                <h3 style="color: #f8fafc; margin: 0 0 16px; font-size: 16px;">Hardened Pipeline Protections</h3>
                <ul style="color: #94a3b8; font-size: 13px; line-height: 1.8; margin: 0; padding-left: 20px;">
                    <li><strong style="color: #cbd5e1;">MIME Cross-Check:</strong> Verifies detected MIME types against integer binary constants (<code style="color: #38bdf8;">IMAGETYPE_*</code>), preventing polyglot attacks.</li>
                    <li><strong style="color: #cbd5e1;">Memory Pre-Flight:</strong> Prevents decompression bombs / OOM crashes before allocating GD decode buffers.</li>
                    <li><strong style="color: #cbd5e1;">SVG Sanitizer:</strong> XML parser strips script tags, event handlers, and XXE entities with <code style="color: #38bdf8;">LIBXML_NONET</code>.</li>
                    <li><strong style="color: #cbd5e1;">Path-Jailed Storage:</strong> Validates realpaths post-construction; files written with restrictive <code style="color: #38bdf8;">0640</code> permissions.</li>
                </ul>
            </div>
        </div>
        <?php
    }
}
