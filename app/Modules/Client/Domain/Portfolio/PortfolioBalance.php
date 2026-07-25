<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Portfolio;

use App\Modules\Client\Domain\Exceptions\ClientDomainException;

/** Opera importes informativos exactos con cuatro decimales. */
final class PortfolioBalance
{
    public static function normalize(string $amount): string
    {
        if (preg_match('/^\d{1,15}(?:\.\d{1,4})?$/D', $amount) !== 1) {
            throw ClientDomainException::portfolioInvalid('El importe debe ser un decimal positivo con hasta cuatro decimales.');
        }

        $normalized = bcadd($amount, '0', 4);
        if (bccomp($normalized, '0.0000', 4) <= 0) {
            throw ClientDomainException::portfolioInvalid('El importe debe ser mayor que cero.');
        }

        return $normalized;
    }

    /** @param iterable<array{entry_type:string,amount:?string}> $entries */
    public static function calculate(iterable $entries): string
    {
        $balance = '0.0000';
        foreach ($entries as $entry) {
            if ($entry['amount'] === null) {
                continue;
            }

            $balance = $entry['entry_type'] === PortfolioEntryType::VOUCHER->value
                ? bcadd($balance, $entry['amount'], 4)
                : bcsub($balance, $entry['amount'], 4);
        }

        return $balance;
    }
}
