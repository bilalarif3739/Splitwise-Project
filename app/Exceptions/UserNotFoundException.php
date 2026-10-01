<?php

declare(strict_types=1);

namespace App\Exceptions;

final class UserNotFoundException extends BusinessException
{
    public function __construct(string $message = 'The requested user does not exist.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return 404;
    }
}