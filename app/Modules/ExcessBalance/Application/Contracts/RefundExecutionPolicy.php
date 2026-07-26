<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Contracts;

interface RefundExecutionPolicy
{
    /** @param array<string, mixed> $fields */
    public function validate(string $method, array $fields): void;
}
