<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Resultado financiero evaluado después de la fecha límite. */
enum PostDueEvaluation: string
{
    case NOT_EVALUATED = 'NO_EVALUADA';
    case SETTLED = 'LIQUIDO';
    case INSTALLMENT = 'ABONO';
    case NO_PAYMENT = 'NO_PAGO';
}
