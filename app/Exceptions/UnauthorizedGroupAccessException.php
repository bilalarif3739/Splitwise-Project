<?php

declare(strict_types=1);

namespace App\Exceptions;

final class UnauthorizedGroupAccessException extends BusinessException
{
    public function __construct(string $message = 'You are not a member of this group.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return 403;
    }
}