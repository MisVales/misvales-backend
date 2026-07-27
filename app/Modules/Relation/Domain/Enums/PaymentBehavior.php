<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\Enums;

enum PaymentBehavior: string
{
    case SIN_CLASIFICAR = 'SIN_CLASIFICAR';
    case PAGO_ANTICIPADO = 'PAGO_ANTICIPADO';
    case PAGO_PUNTUAL = 'PAGO_PUNTUAL';
    case PAGO_FUERA_DE_TIEMPO = 'PAGO_FUERA_DE_TIEMPO';
    case ABONO = 'ABONO';
    case FALTA_DE_PAGO = 'FALTA_DE_PAGO';
}
