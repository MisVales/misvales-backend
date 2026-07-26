<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Integrations;

use App\Modules\Payment\Application\Contracts\PaymentAuthorizationPort;
use App\Modules\Payment\Application\Security\PaymentActorContext;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;

/** Deniega operaciones críticas mientras M01 no publique el contrato requerido. */
final class UnavailablePaymentAuthorizationPort implements PaymentAuthorizationPort
{
    public function assertAndConsume(
        string $authorizationId,
        string $operation,
        PaymentActorContext $actor,
        string $resourceId,
        string $amount,
        int $branchId,
    ): void {
        throw PaymentDomainException::authorizationContractUnavailable();
    }
}
