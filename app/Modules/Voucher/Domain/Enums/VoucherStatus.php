<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Enums;

enum VoucherStatus: string
{
    case GENERATED = 'GENERADO';
    case COUNTER_VALIDATION = 'VALIDACION_CAJA';
    case CORRECTION_PENDING = 'CORRECCION_PENDIENTE';
    case RELEASED = 'LIBERADO';
    case FULFILLED = 'FERIADO';
    case REJECTED = 'RECHAZADO';
    case CANCELLED = 'CANCELADO';

    public function isTerminal(): bool
    {
        return in_array($this, [self::FULFILLED, self::REJECTED, self::CANCELLED], true);
    }
}
