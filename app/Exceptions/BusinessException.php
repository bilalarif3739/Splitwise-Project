<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base class for expected, business-level failures.
 *
 * Anything extending this is rendered as a clean envelope response with the
 * status code declared below, and is never reported as an application bug.
 */
abstract class BusinessException extends RuntimeException
{
    abstract public function statusCode(): int;

    /**
     * Optional field-level details for the "errors" key.
     *
     * @return array<string, array<int, string>>|null
     */
    public function errors(): ?array
    {
        return null;
    }
}