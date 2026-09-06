<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Compatibility;

use Astraea\Security\SecurityEventManager;

/**
 * Granular Compatibility Layer and Exception Controller.
 *
 * Enforces the "minimum effective exception" doctrine: overrides apply only
 * to specific features without globally disabling security hardening layers.
 *
 * @package Astraea\Compatibility
 */
final class CompatibilityManager {

    public const OPTION_FLAGS = 'astraea_compatibility_flags';

    public static function init(): void {
        // 1. XML-RPC exception hook
        add_filter('xmlrpc_enabled', [self::class, 'filterXmlRpc'], 99);

        // 2. REST API authentication relaxation hook
        add_filter('rest_authentication_errors', [self::class, 'filterRestAuth'], 99);
    }

    public static function isEnabled(CompatibilityFlag $flag): bool {
        $flags = self::getFlags();
        return !empty($flags[$flag->value]);
    }

    /**
     * @param array<string, bool> $flags
     */
    public static function setFlags(array $flags): void {
        update_option(self::OPTION_FLAGS, $flags);

        if (class_exists(SecurityEventManager::class)) {
            SecurityEventManager::recordOnce(
                SecurityEventManager::SEVERITY_SECURITY,
                'Compatibility',
                'compatibility_flags_updated',
                'Astraea compatibility exceptions modified by administrator.',
                ['active_flags' => array_keys(array_filter($flags))],
                60
            );
        }
    }

    /**
     * @return array<string, bool>
     */
    public static function getFlags(): array {
        $raw = function_exists('get_option') ? get_option(self::OPTION_FLAGS, []) : [];
        return is_array($raw) ? $raw : [];
    }

    public static function filterXmlRpc(bool $enabled): bool {
        return self::isEnabled(CompatibilityFlag::ALLOW_LEGACY_XMLRPC);
    }

    public static function filterRestAuth(mixed $errors): mixed {
        if (self::isEnabled(CompatibilityFlag::RELAX_REST_AUTHENTICATION)) {
            return null; // Do not enforce mandatory authentication on public GET endpoints
        }
        return $errors;
    }
}
