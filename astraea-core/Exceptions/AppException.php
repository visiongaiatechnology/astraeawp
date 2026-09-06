<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Exceptions;

use Exception;
use Throwable;

/**
 * AstraeaOS VGT Supreme Base Application Exception.
 *
 * Root of the typed exception hierarchy adhering to VGT Mandatory Code Pattern 1.5.A.
 *
 * @package Astraea\Exceptions
 */
class AppException extends Exception {

    /**
     * @var array<string, mixed>
     */
    protected array $context;

    /**
     * @param string $message Developer/internal error message.
     * @param int $code Error status/code.
     * @param Throwable|null $previous Previous throwable in chain.
     * @param array<string, mixed> $context Structured context data for audit logging.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get structured audit context.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array {
        return $this->context;
    }
}
