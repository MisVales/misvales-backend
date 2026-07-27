<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\Enums;

enum CutRunStatus: string
{
    case PENDIENTE = 'PENDIENTE';
    case EJECUTANDO = 'EJECUTANDO';
    case COMPLETADA = 'COMPLETADA';
    case COMPLETADA_CON_ERRORES = 'COMPLETADA_CON_ERRORES';
    case FALLIDA = 'FALLIDA';
}
