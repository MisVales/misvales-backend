<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Estados permitidos de una aclaración de pago. */
enum ClarificationStatus: string
{
    case REGISTERED = 'REGISTRADA';
    case UNDER_REVIEW = 'EN_REVISION';
    case MOVEMENT_LINKED = 'MOVIMIENTO_VINCULADO';
    case NO_MATCH = 'SIN_COINCIDENCIA';
    case RECONCILIATION_REQUESTED = 'CONCILIACION_SOLICITADA';
    case RESOLVED = 'RESUELTA';
    case REJECTED = 'RECHAZADA';
}
