<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Enums;

enum DataChangeRequestStatus: string
{
    case PENDING = 'PENDIENTE';
    case AUTHORIZED = 'AUTORIZADO';
    case REJECTED = 'RECHAZADO';
    case USED = 'USADO';
    case EXPIRED = 'VENCIDO';

    public function isTerminal(): bool
    {
        return in_array($this, [self::REJECTED, self::USED, self::EXPIRED], true);
    }
}
