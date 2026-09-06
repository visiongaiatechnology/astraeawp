<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Security;

/**
 * AstraeaOS Unified Header Policy Service.
 *
 * Implements security header emission with strict adherence to VGT Section 3.3.
 * Remediates Finding 6: HSTS preload and includeSubDomains are strictly OPT-IN,
 * preventing irrecoverable domain damage in non-preload-ready environments.
 *
 * @package Astraea\Security
 */
final class HeaderPolicyService {

    private static bool $sent = false;

    /**
     * Initialize header hooks in WordPress.
     */
    public static function init(): void {
        if (function_exists('add_action')) {
            add_action('send_headers', [self::class, 'send'], 1);
        }
        if (function_exists('add_filter')) {
            add_filter('wp_headers', [self::class, 'filterHeaders'], 10);
        }
    }

    /**
     * Directly emit hardened security headers if headers are not already sent.
     */
    public static function send(): void {
        if (self::$sent || headers_sent() || self::isTitanAuthoritative()) {
            return;
        }

        // 1. Remove PHP banner leakage
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        $headers = self::buildHeaders();
        foreach ($headers as $name => $value) {
            header("{$name}: {$value}", true);
        }

        self::$sent = true;
    }

    /**
     * Filter array of WordPress headers.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function filterHeaders(array $headers): array {
        if (self::isTitanAuthoritative()) {
            return $headers;
        }
        $secHeaders = self::buildHeaders();
        return array_merge($headers, $secHeaders);
    }

    /**
     * GeDefense Titan is the authoritative HTTP policy engine when it is both
     * enabled and actually loaded. Astraea HeaderPolicy remains the zero-
     * dependency baseline/fallback and never competes for the same headers.
     */
    public static function isTitanAuthoritative(): bool {
        if (!class_exists('\VIS_Titan') || !function_exists('get_option')) {
            return false;
        }
        $config = get_option('vis_config', []);
        return is_array($config) && !empty($config['titan_enabled']);
    }

    /**
     * Build the canonical security headers map.
     *
     * @return array<string, string>
     */
    public static function buildHeaders(): array {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];

        if (self::isHstsEnabled()) {
            $headers['Strict-Transport-Security'] = self::buildHstsHeader();
        }

        return $headers;
    }

    /**
     * Construct the Strict-Transport-Security header value.
     *
     * In accordance with VGT Hardening Remediation:
     * - Default max-age is 31,536,000 seconds (1 year).
     * - includeSubDomains is strictly opt-in (ASTRAEA_HSTS_INCLUDE_SUBDOMAINS constant or filter).
     * - preload is strictly opt-in (ASTRAEA_HSTS_PRELOAD constant or filter).
     *
     * @return string
     */
    public static function buildHstsHeader(): string {
        $maxAge = 31536000;
        if (defined('ASTRAEA_HSTS_MAX_AGE')) {
            $maxAge = (int) ASTRAEA_HSTS_MAX_AGE;
        }

        $parts = ["max-age={$maxAge}"];

        $subdomains = false;
        if (defined('ASTRAEA_HSTS_INCLUDE_SUBDOMAINS') && ASTRAEA_HSTS_INCLUDE_SUBDOMAINS === true) {
            $subdomains = true;
        }
        if (function_exists('apply_filters')) {
            $subdomains = (bool) apply_filters('astraea_hsts_include_subdomains', $subdomains);
        }
        if ($subdomains) {
            $parts[] = 'includeSubDomains';
        }

        $preload = false;
        if (defined('ASTRAEA_HSTS_PRELOAD') && ASTRAEA_HSTS_PRELOAD === true) {
            $preload = true;
        }
        if (function_exists('apply_filters')) {
            $preload = (bool) apply_filters('astraea_hsts_preload', $preload);
        }
        if ($preload) {
            $parts[] = 'preload';
        }

        return implode('; ', $parts);
    }

    /**
     * Check if HSTS is enabled for current request context.
     */
    public static function isHstsEnabled(): bool {
        if (defined('ASTRAEA_HSTS_DISABLED') && ASTRAEA_HSTS_DISABLED === true) {
            return false;
        }
        return self::isHttps();
    }

    /**
     * Detect if current request is transported over TLS/HTTPS.
     */
    public static function isHttps(): bool {
        if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === '1')) {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (self::isTrustedProxy((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
            if ($forwardedProto === 'https' || $forwardedSsl === 'on') {
                return true;
            }
        }
        return false;
    }

    /**
     * Trust forwarded TLS metadata only from explicitly configured proxy CIDRs.
     */
    public static function isTrustedProxy(string $remoteAddress): bool {
        if ($remoteAddress === '' || !defined('ASTRAEA_TRUSTED_PROXY_CIDRS')) {
            return false;
        }

        $configuredCidrs = ASTRAEA_TRUSTED_PROXY_CIDRS;
        if (!is_array($configuredCidrs)) {
            return false;
        }

        foreach ($configuredCidrs as $cidr) {
            if (is_string($cidr) && self::ipInCidr($remoteAddress, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool {
        [$network, $prefixText] = array_pad(explode('/', trim($cidr), 2), 2, '');
        $ipBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        $maxBits = strlen($ipBytes) * 8;
        $prefix = $prefixText === '' ? $maxBits : filter_var($prefixText, FILTER_VALIDATE_INT);
        if ($prefix === false || $prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($wholeBytes > 0 && substr($ipBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }
}
