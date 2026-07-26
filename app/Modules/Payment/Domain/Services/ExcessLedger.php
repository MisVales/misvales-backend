<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Services;

use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use App\Modules\Payment\Domain\ValueObjects\Money;

/** Comprueba la exclusión contable entre disponible, aplicado, reservado y devuelto. */
final class ExcessLedger
{
    public function assertInvariant(
        Money $original,
        Money $available,
        Money $applied,
        Money $refunded,
        Money $reserved,
    ): void {
        foreach ([$original, $available, $applied, $refunded, $reserved] as $amount) {
            if ($amount->isNegative()) {
                throw PaymentDomainException::excessUnavailable();
            }
        }

        if (! $available->add($applied)->add($refunded)->add($reserved)->equals($original)) {
            throw PaymentDomainException::excessInvariantViolation();
        }
    }
}
