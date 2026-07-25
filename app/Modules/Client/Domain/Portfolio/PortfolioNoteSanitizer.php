<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Portfolio;

use App\Modules\Client\Domain\Exceptions\ClientDomainException;

/** Sanea notas y rechaza indicadores explícitos de credenciales o tokens. */
final class PortfolioNoteSanitizer
{
    public function normalize(?string $note): ?string
    {
        if ($note === null || trim($note) === '') {
            return null;
        }
        $sanitized = trim(strip_tags($note));
        $maximum = (int) config('client.portfolio_note_max_length', 1000);
        if (mb_strlen($sanitized) > $maximum) {
            throw ClientDomainException::portfolioInvalid('La nota excede la longitud permitida.');
        }
        if (preg_match('/\b(password|contrase(?:ña|na)|token|bearer|api[_ -]?key|secret)\b/iu', $sanitized) === 1) {
            throw ClientDomainException::portfolioInvalid('La nota no puede contener credenciales, tokens ni secretos.');
        }

        return $sanitized;
    }
}
