<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;

/** Conserva el folio recibido y una representación normalizada no difusa. */
final readonly class BankFolio
{
    public string $raw;

    public string $normalized;

    public function __construct(string $raw)
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || mb_strlen($trimmed) > 160) {
            throw PaymentDomainException::invalidBankRow(['bank_folio' => ['El folio bancario es obligatorio y admite hasta 160 caracteres.']]);
        }
        if (str_starts_with($trimmed, '=')) {
            throw PaymentDomainException::invalidBankRow(['bank_folio' => ['El folio bancario no admite fórmulas.']]);
        }

        $this->raw = $raw;
        $this->normalized = mb_strtoupper($trimmed);
    }
}
