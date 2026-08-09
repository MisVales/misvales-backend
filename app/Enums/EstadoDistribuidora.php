<?php

namespace App\Enums;

enum EstadoDistribuidora: string
{
    case PENDIENTE_ACTIVACION = 'PENDING_ACTIVATION';
    case ACTIVA = 'ACTIVE';
    case DESHABILITADA = 'DISABLED';
}
