<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\Enums;

enum CutAttemptStatus: string
{
    case PENDIENTE = 'PENDIENTE';
    case EJECUTANDO = 'EJECUTANDO';
    case GENERADA = 'GENERADA';
    case SIN_PARTIDAS = 'SIN_PARTIDAS';
    case FALLIDA = 'FALLIDA';
}
