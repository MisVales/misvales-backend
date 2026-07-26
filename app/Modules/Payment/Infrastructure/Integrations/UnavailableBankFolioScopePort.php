<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Integrations;

use App\Modules\Payment\Application\Contracts\BankFolioScopePort;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;

/** No presume si el folio es global, por banco, cuenta o sucursal. */
final class UnavailableBankFolioScopePort implements BankFolioScopePort
{
    public function scopeFor(int $branchId, string $normalizedFolio): string
    {
        throw PaymentDomainException::folioScopeUnavailable();
    }
}
