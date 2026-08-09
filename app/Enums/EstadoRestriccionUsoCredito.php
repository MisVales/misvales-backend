<?php

namespace App\Enums;

enum EstadoRestriccionUsoCredito: string
{
    case ACTIVA = 'ACTIVE';
    case CONSUMIDA = 'CONSUMED';
    case CANCELADA = 'CANCELLED';
}
