<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Domain\Enums;

enum FinancialResult: string
{
    case LIQUIDO = 'LIQUIDO';
    case ABONO = 'ABONO';
    case NO_PAGO = 'NO_PAGO';
}
