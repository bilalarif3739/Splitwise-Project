<?php

declare(strict_types=1);

namespace App\Exceptions;

final class UnauthenticatedException extends BusinessException
{
    public function __construct(string $message = 'Invalid or missing authentication token.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return 401;
    }
}