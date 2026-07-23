<?php

namespace App\Modules\Access\Domain\Accounts;

use RuntimeException;

/**
 * Represents an expected access-domain rejection that can be converted to an API response.
 */
final class AccessRuleViolation extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 422)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
