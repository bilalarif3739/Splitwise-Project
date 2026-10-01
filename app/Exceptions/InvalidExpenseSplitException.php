<?php

declare(strict_types=1);

namespace App\Exceptions;

final class InvalidExpenseSplitException extends BusinessException
{
    /**
     * @param array<string, array<int, string>>|null $errors
     */
    public function __construct(
        string $message = 'The expense split is invalid.',
        private readonly ?array $errors = null,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errors(): ?array
    {
        return $this->errors;
    }
}