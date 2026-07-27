<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\Enums;

enum FinancialStatus: string
{
    case PENDIENTE = 'PENDIENTE';
    case ABONADA = 'ABONADA';
    case LIQUIDADA = 'LIQUIDADA';
    case VENCIDA = 'VENCIDA';
}
