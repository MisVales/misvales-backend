<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Integrations;

use App\Modules\Payment\Application\Contracts\RefundMethodContract;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;

/** Deniega devoluciones hasta definir métodos y campos bancarios válidos. */
final class UnavailableRefundMethodContract implements RefundMethodContract
{
    public function assertValid(string $method, array $fields): void
    {
        throw PaymentDomainException::refundContractUnavailable();
    }
}
