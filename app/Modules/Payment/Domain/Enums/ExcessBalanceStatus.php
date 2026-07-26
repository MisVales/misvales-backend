<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Destino y disponibilidad de un excedente registrado. */
enum ExcessBalanceStatus: string
{
    case PENDING_DECISION = 'PENDIENTE_DECISION';
    case CREDIT_BALANCE = 'SALDO_A_FAVOR';
    case PARTIALLY_APPLIED = 'APLICADO_PARCIAL';
    case FULLY_APPLIED = 'APLICADO_TOTAL';
    case REFUND_PENDING = 'DEVOLUCION_PENDIENTE';
    case REFUNDED = 'DEVUELTO';
}
