<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Exceptions;

/**
 * Validation Boundary Exception.
 *
 * User-Facing: Message is validated safe for verbatim presentation to the user/client.
 * Represents input constraint violations, parameter boundary bounds, and malformed payload formats.
 *
 * @package Astraea\Exceptions
 */
class ValidationException extends AppException {}
