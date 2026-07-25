<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Enums;

enum IncreaseRequestStatus: string
{
    case REQUESTED = 'SOLICITADO';
    case PREAUTHORIZED = 'PREAUTORIZADO';
    case REJECTED_BY_COORDINATOR = 'RECHAZADO_COORDINADOR';
    case REJECTED_BY_MANAGER = 'RECHAZADO_GERENTE';
    case FULLY_AUTHORIZED = 'AUTORIZADO_TOTAL';
    case PARTIALLY_AUTHORIZED = 'AUTORIZADO_PARCIAL';
    case FIFTY_PERCENT_RESTRICTION_ACTIVE = 'RESTRICCION_50_ACTIVA';
    case COMPLETED = 'COMPLETADO';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::REJECTED_BY_COORDINATOR,
            self::REJECTED_BY_MANAGER,
            self::COMPLETED,
        ], true);
    }
}
