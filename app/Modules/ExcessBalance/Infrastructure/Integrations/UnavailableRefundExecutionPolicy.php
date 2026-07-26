<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Integrations;

use App\Modules\ExcessBalance\Application\Contracts\RefundExecutionPolicy;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;

final class UnavailableRefundExecutionPolicy implements RefundExecutionPolicy
{
    public function validate(string $method, array $fields): void
    {
        throw ExcessBalanceException::refundExecutionUndefined();
    }
}
