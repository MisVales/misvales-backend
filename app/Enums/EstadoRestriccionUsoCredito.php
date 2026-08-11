<?php

namespace App\Enums;

enum EstadoRestriccionUsoCredito: string
{
    case ACTIVA = 'ACTIVE';
    case RESERVADA = 'RESERVED';
    case CONSUMIDA = 'CONSUMED';
    case CANCELADA = 'CANCELLED';
}
