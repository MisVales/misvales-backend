<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Enums;

/** Clasificación definitiva recibida del contrato financiero de M11. */
enum LiquidationClassification: string
{
    case ANTICIPADA = 'ANTICIPADA';
    case PUNTUAL = 'PUNTUAL';
    case FUERA_DE_TIEMPO = 'FUERA_DE_TIEMPO';
    case ABONO = 'ABONO';
    case FALTA_DE_PAGO = 'FALTA_DE_PAGO';
    case SIN_CLASIFICAR = 'SIN_CLASIFICAR';

    public function isFinal(): bool
    {
        return in_array($this, [self::ANTICIPADA, self::PUNTUAL, self::FUERA_DE_TIEMPO], true);
    }
}
