<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Exceptions;

/**
 * Security Threat & Adversarial Violation Exception.
 *
 * Internal Exception: Full diagnostic detail is written exclusively to internal audit logs.
 * Client presentation MUST remain strictly opaque to prevent attacker oracle leakage.
 *
 * Triggered on: injection, CSRF, polyglot, origin, path, traversal, token, MAC tampering.
 *
 * @package Astraea\Exceptions
 */
class SecurityException extends AppException {

    /**
     * Opaque generic response string for client presentation.
     */
    public const OPAQUE_CLIENT_MESSAGE = 'Request rejected for security reasons.';

    /**
     * Get client-safe opaque error message.
     */
    public function getOpaqueMessage(): string {
        return self::OPAQUE_CLIENT_MESSAGE;
    }
}
