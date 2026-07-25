<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Enums;

/**
 * Comportamientos de pago reconocidos por la política de puntos (C06/C07).
 *
 * Cada comportamiento tiene reglas definidas sobre si genera o reduce puntos,
 * representadas en la configuración PAYMENT_BEHAVIOR_POINTS_POLICY.
 */
enum PaymentBehavior: string
{
    case EARLY_PAYMENT = 'EARLY_PAYMENT';
    case ON_TIME_PAYMENT = 'ON_TIME_PAYMENT';
    case LATE_PAYMENT = 'LATE_PAYMENT';
    case PARTIAL_PAYMENT = 'PARTIAL_PAYMENT';
    case NO_PAYMENT = 'NO_PAYMENT';
}
