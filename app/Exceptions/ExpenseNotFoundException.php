<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ExpenseNotFoundException extends BusinessException
{
    public function __construct(string $message = 'The requested expense does not exist.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return 404;
    }
}