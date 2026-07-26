<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Estados permitidos de una fila o movimiento bancario importado. */
enum BankMovementStatus: string
{
    case PENDING = 'PENDIENTE';
    case INVALID = 'INVALIDO';
    case RECONCILED = 'CONCILIADO';
    case UNRECONCILED = 'NO_CONCILIADO';
    case DUPLICATE = 'DUPLICADO';
    case MANUAL_REVIEW = 'REVISION_MANUAL';
    case MANUALLY_APPLIED = 'APLICADO_MANUALMENTE';
}
