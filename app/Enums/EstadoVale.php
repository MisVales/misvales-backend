<?php

namespace App\Enums;

enum EstadoVale: string
{
    case GENERADO = 'GENERATED';
    case VALIDACION_CAJA = 'CASH_VALIDATION';
    case CORRECCION_PENDIENTE = 'CORRECTION_PENDING';
    case LIBERADO = 'RELEASED';
    case FERIADO = 'CASHED';
    case RECHAZADO = 'REJECTED';
    case CANCELADO = 'CANCELLED';
}
