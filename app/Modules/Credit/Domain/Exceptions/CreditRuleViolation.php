<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Exceptions;

use RuntimeException;

final class CreditRuleViolation extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $statusCode = 409,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
