<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use Illuminate\Http\UploadedFile;

/**
 * Punto explícito de denegación para transiciones cuyo contrato funcional sigue pendiente.
 *
 * No persiste estados parciales ni simula respuestas.
 */
final class UnavailablePaymentTransitions
{
    public function receiveBankImport(UploadedFile $file): never
    {
        throw PaymentDomainException::fileContractUnavailable();
    }

    public function clarification(UploadedFile $evidence): never
    {
        throw PaymentDomainException::mediaContractUnavailable();
    }

    public function relationDependent(): never
    {
        throw PaymentDomainException::relationContractUnavailable();
    }

    /** @param array<string, mixed> $fields */
    public function refund(string $method, array $fields): never
    {
        throw PaymentDomainException::refundContractUnavailable();
    }
}
