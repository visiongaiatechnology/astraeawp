<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Maintenance;

/**
 * Controller and SEO-Safe Presentation Engine for Maintenance and Coming Soon Modes.
 *
 * @package Astraea\Maintenance
 */
final class MaintenanceController {

    public const OPTION_CONFIG = 'astraea_maintenance_config';
    public const COOKIE_BYPASS = 'astraea_maint_bypass';

    public static function init(): void {
        add_action('template_redirect', [self::class, 'interceptRequest'], -999);
    }

    public static function interceptRequest(): void {
        // Admin screens, login screen, and CLI/CRON never trigger maintenance screen
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || php_sapi_name() === 'cli') {
            return;
        }

        $config = self::getConfig();
        $mode = MaintenanceMode::tryFrom((string)($config['mode'] ?? 'disabled')) ?? MaintenanceMode::DISABLED;

        if ($mode === MaintenanceMode::DISABLED) {
            return;
        }

        // 1. Authenticated administrator bypass
        if (current_user_can('manage_options')) {
            return;
        }

        // 2. Secret Cookie / Query Bypass Check
        $bypassToken = (string)($config['bypass_token'] ?? '');
        if ($bypassToken !== '') {
            if (isset($_GET['astraea_bypass']) && hash_equals($bypassToken, (string)$_GET['astraea_bypass'])) {
                setcookie(self::COOKIE_BYPASS, $bypassToken, [
                    'expires'  => time() + 86400,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
                return;
            }
            if (isset($_COOKIE[self::COOKIE_BYPASS]) && hash_equals($bypassToken, (string)$_COOKIE[self::COOKIE_BYPASS])) {
                return;
            }
        }

        // 3. IP Whitelist Check
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $whitelistedIps = array_filter(array_map('trim', explode("\n", (string)($config['whitelisted_ips'] ?? ''))));
        if (in_array($remoteIp, $whitelistedIps, true)) {
            return;
        }

        // 4. Serve Maintenance Response
        if ($mode === MaintenanceMode::MAINTENANCE) {
            http_response_code(503);
            header('Retry-After: 3600');
            header('X-Astraea-Maintenance: Active');
        } else {
            http_response_code(200);
            header('X-Astraea-Coming-Soon: Active');
        }

        self::renderFrontendTemplate($config);
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfig(): array {
        $raw = get_option(self::OPTION_CONFIG, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function updateConfig(array $config): void {
        update_option(self::OPTION_CONFIG, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function renderFrontendTemplate(array $config): void {
        $title = (string)($config['title'] ?? 'Scheduled Maintenance');
        $message = (string)($config['message'] ?? 'We are performing scheduled core maintenance to ensure optimal security and reliability. Please check back shortly.');
        $siteName = get_bloginfo('name');
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($title); ?> &bull; <?php echo esc_html($siteName); ?></title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
                body {
                    background-color: #030712;
                    color: #f8fafc;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 24px;
                    background-image: radial-gradient(circle at 50% 30%, rgba(14, 165, 233, 0.12) 0%, transparent 60%);
                }
                .card {
                    background: rgba(15, 23, 42, 0.8);
                    backdrop-filter: blur(24px);
                    border: 1px solid rgba(255, 255, 255, 0.1);
                    border-radius: 16px;
                    padding: 40px;
                    max-width: 520px;
                    width: 100%;
                    text-align: center;
                    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
                }
                .icon {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 54px;
                    height: 54px;
                    border-radius: 50%;
                    background: rgba(56, 189, 248, 0.15);
                    border: 1px solid rgba(56, 189, 248, 0.3);
                    color: #38bdf8;
                    font-size: 24px;
                    margin-bottom: 20px;
                }
                h1 { font-size: 24px; font-weight: 700; color: #f8fafc; margin-bottom: 12px; }
                p { font-size: 14px; line-height: 1.6; color: #94a3b8; margin-bottom: 24px; }
                .footer { font-size: 12px; color: #64748b; }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="icon">⚡</div>
                <h1><?php echo esc_html($title); ?></h1>
                <p><?php echo nl2br(esc_html($message)); ?></p>
                <div class="footer"><?php echo esc_html($siteName); ?> &bull; Powered by AstraeaOS WP</div>
            </div>
        </body>
        </html>
        <?php
    }
}
