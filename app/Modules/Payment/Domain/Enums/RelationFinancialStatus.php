<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Estado financiero derivado de una relación después de aplicar fondos. */
enum RelationFinancialStatus: string
{
    case PENDING = 'PENDIENTE';
    case PARTIALLY_PAID = 'ABONADA';
    case SETTLED = 'LIQUIDADA';
    case OVERDUE = 'VENCIDA';
}
