<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Estados permitidos del ciclo de una importación bancaria. */
enum BankImportStatus: string
{
    case RECEIVED = 'RECIBIDO';
    case VALIDATING = 'VALIDANDO';
    case REJECTED = 'RECHAZADO';
    case PROCESSING = 'PROCESANDO';
    case PROCESSED = 'PROCESADO';
    case FAILED = 'FALLIDO';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::RECEIVED => $next === self::VALIDATING,
            self::VALIDATING => in_array($next, [self::REJECTED, self::PROCESSING], true),
            self::PROCESSING => in_array($next, [self::PROCESSED, self::FAILED], true),
            self::FAILED => $next === self::PROCESSING,
            self::REJECTED, self::PROCESSED => false,
        };
    }
}
