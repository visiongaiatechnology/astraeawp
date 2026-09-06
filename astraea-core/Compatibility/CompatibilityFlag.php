<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Compatibility;

/**
 * Granular Compatibility Flags for Legacy WordPress Ecosystem Interoperability.
 *
 * @package Astraea\Compatibility
 */
enum CompatibilityFlag: string {
    case ALLOW_LEGACY_XMLRPC         = 'allow_legacy_xmlrpc';
    case RELAX_REST_AUTHENTICATION   = 'relax_rest_authentication';
    case ALLOW_LEGACY_PHPMAILER      = 'allow_legacy_phpmailer';
    case RELAX_UPLOAD_EXTENSIONS     = 'relax_upload_extensions';
    case ALLOW_UNFILTERED_HTML_ADMIN = 'allow_unfiltered_html_admin';

    public function label(): string {
        return match ($this) {
            self::ALLOW_LEGACY_XMLRPC         => 'Legacy XML-RPC Access',
            self::RELAX_REST_AUTHENTICATION   => 'Relaxed REST Read Access',
            self::ALLOW_LEGACY_PHPMAILER      => 'Plaintext PHP Mailer Transport',
            self::RELAX_UPLOAD_EXTENSIONS     => 'Extended Upload Extensions',
            self::ALLOW_UNFILTERED_HTML_ADMIN => 'Unfiltered HTML for Administrators',
        };
    }

    public function risk(): string {
        return match ($this) {
            self::ALLOW_LEGACY_XMLRPC         => 'HIGH',
            self::RELAX_REST_AUTHENTICATION   => 'MEDIUM',
            self::ALLOW_LEGACY_PHPMAILER      => 'MEDIUM',
            self::RELAX_UPLOAD_EXTENSIONS     => 'CRITICAL',
            self::ALLOW_UNFILTERED_HTML_ADMIN => 'HIGH',
        };
    }

    public function defaultReason(): string {
        return match ($this) {
            self::ALLOW_LEGACY_XMLRPC         => 'Required by legacy external desktop publishing or mobile apps.',
            self::RELAX_REST_AUTHENTICATION   => 'Allows unauthenticated GET requests to custom plugin REST namespaces.',
            self::ALLOW_LEGACY_PHPMAILER      => 'Allows plugins expecting standard PHP mail() transport without strict TLS.',
            self::RELAX_UPLOAD_EXTENSIONS     => 'Permits non-standard file extensions bypassed by FileGuard.',
            self::ALLOW_UNFILTERED_HTML_ADMIN => 'Permits administrator role to save raw script tags in post content.',
        };
    }
}
