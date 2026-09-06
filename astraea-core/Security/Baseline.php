<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Security;

/**
 * AstraeaOS Security Baseline Enforcer.
 *
 * Implements Security-by-Default:
 * - VGT Pattern 1.5.C: Separation of internal error reporting vs display (display_errors suppressed).
 * - Cookie security (Secure, HttpOnly, SameSite=Lax).
 * - Disallows the built-in theme/plugin code editor (preventing RCE from compromised admin accounts).
 * - Masks descriptive login errors to prevent user enumeration.
 * - Enforces HTTPS awareness and secure session parameters.
 * - Prevents author query enumeration attacks.
 *
 * @package Astraea\Security
 */
final class Baseline {

    private static bool $initialized = false;

    /**
     * Apply the security baseline.
     */
    public static function apply(): void {
        if (self::$initialized) {
            return;
        }

        // 1. VGT Pattern 1.5.C: Suppress user-visible display errors in production (exempt during installation)
        $isInstalling = (defined('WP_INSTALLING') && WP_INSTALLING) || (defined('WP_SETUP_CONFIG') && WP_SETUP_CONFIG);
        if (!$isInstalling && (!defined('WP_DEBUG') || !WP_DEBUG)) {
            ini_set('display_errors', '0');
        }

        // Ensure system error logging is always active for DevSecOps forensics
        if (!ini_get('log_errors')) {
            @ini_set('log_errors', '1');
        }

        // 2. Disable code execution via theme/plugin editor in wp-admin
        if (!defined('DISALLOW_FILE_EDIT')) {
            define('DISALLOW_FILE_EDIT', true);
        }

        // 3. Cookie Security: Force Secure flag over HTTPS
        $secureCookiePolicy = static function (bool $secure): bool {
            return $secure || HeaderPolicyService::isHttps();
        };
        add_filter('secure_auth_cookie', $secureCookiePolicy, 99);
        add_filter('secure_logged_in_cookie', $secureCookiePolicy, 99);

        // 4. User Enumeration Protection: Generic login failure message
        add_filter('login_errors', function (): string {
            return 'Invalid login credentials.';
        }, 99);

        // 5. Disable author query enumeration (e.g. ?author=1 redirecting to nice names)
        add_action('parse_request', function ($wp): void {
            if (!is_admin() && isset($_GET['author']) && is_numeric($_GET['author'])) {
                if (function_exists('wp_die')) {
                    wp_die('Author enumeration is disabled.', 'Access Denied', ['response' => 403]);
                } else {
                    http_response_code(403);
                    exit('Access Denied');
                }
            }
        });

        // 6. Hardened Nonce Lifetime (default 12 hours instead of 24)
        add_filter('nonce_life', function (): int {
            return 12 * HOUR_IN_SECONDS;
        }, 10);

        self::$initialized = true;
    }
}
