<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Exceptions;

/**
 * Storage & Database Layer Fault Exception.
 *
 * Internal Exception: Full query and database diagnostic details are written
 * exclusively to internal audit logs. Client presentation MUST remain strictly opaque
 * to prevent leaking internal database schemas, table names, or credentials.
 *
 * @package Astraea\Exceptions
 */
class StorageException extends AppException {

    /**
     * Opaque generic response string for client presentation.
     */
    public const OPAQUE_CLIENT_MESSAGE = 'A server error occurred.';

    /**
     * Get client-safe opaque error message.
     */
    public function getOpaqueMessage(): string {
        return self::OPAQUE_CLIENT_MESSAGE;
    }
}
