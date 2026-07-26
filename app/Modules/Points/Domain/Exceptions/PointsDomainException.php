<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Exceptions;

use RuntimeException;

final class PointsDomainException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $fields
     */
    public function __construct(
        private readonly string $domainCode,
        string $message,
        private readonly int $httpStatus = 422,
        private readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->domainCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, list<string>> */
    public function fields(): array
    {
        return $this->fields;
    }
}
