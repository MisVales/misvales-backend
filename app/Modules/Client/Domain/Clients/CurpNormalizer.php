<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Clients;

use App\Modules\Client\Domain\Exceptions\ClientDomainException;

/** Normaliza una CURP sin afirmar validación contra fuentes gubernamentales. */
final class CurpNormalizer
{
    public function normalize(string $value): string
    {
        $normalized = mb_strtoupper(trim($value), 'UTF-8');

        if (preg_match('/^[A-Z0-9]{18}$/D', $normalized) !== 1) {
            throw ClientDomainException::curpInvalid();
        }

        return $normalized;
    }
}
