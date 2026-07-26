<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Estados de autorización y ejecución de una conciliación manual. */
enum ManualReconciliationStatus: string
{
    case DRAFT = 'BORRADOR';
    case PENDING_AUTHORIZATION = 'PENDIENTE_AUTORIZACION';
    case AUTHORIZED = 'AUTORIZADA';
    case REJECTED = 'RECHAZADA';
    case APPLIED = 'APLICADA';
    case EXPIRED = 'VENCIDA';
    case CANCELLED = 'CANCELADA';
}
