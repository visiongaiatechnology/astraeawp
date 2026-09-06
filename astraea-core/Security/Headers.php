<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Security;

/**
 * AstraeaOS HTTP Security Headers Manager.
 *
 * Emits hardened response headers mitigating clickjacking, MIME sniffing,
 * credential leakage, and insecure transport according to VGT Section 3.3.
 *
 * @package Astraea\Security
 */
final class Headers {

    private static bool $sent = false;

    /**
     * Attach headers to WordPress send_headers action.
     */
    public static function init(): void {
        HeaderPolicyService::init();
    }

    /**
     * Emit security headers directly if not already sent.
     */
    public static function send(): void {
        HeaderPolicyService::send();
    }

    /**
     * Filter WordPress header array.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function filterHeaders(array $headers): array {
        return HeaderPolicyService::filterHeaders($headers);
    }
}
