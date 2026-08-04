<?php

namespace App\Enums;

enum EstadoDeclaracionSeccion: string
{
    case PENDIENTE = 'PENDING';
    case COMPLETADA = 'COMPLETED';
    case NO_APLICA = 'NOT_APPLICABLE';
}
