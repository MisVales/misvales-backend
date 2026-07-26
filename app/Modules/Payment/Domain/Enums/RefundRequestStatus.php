<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Estados permitidos de una solicitud de devolución. */
enum RefundRequestStatus: string
{
    case PENDING_AUTHORIZATION = 'PENDIENTE_AUTORIZACION';
    case AUTHORIZED = 'AUTORIZADA';
    case REJECTED = 'RECHAZADA';
    case CANCELLED = 'CANCELADA';
    case COMPLETED = 'COMPLETADA';
}
