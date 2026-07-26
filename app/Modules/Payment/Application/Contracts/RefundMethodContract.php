<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contracts;

/** Valida método y campos de una devolución externa ya ejecutada. */
interface RefundMethodContract
{
    /** @param array<string, mixed> $fields */
    public function assertValid(string $method, array $fields): void;
}
