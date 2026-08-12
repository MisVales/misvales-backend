<?php

namespace App\Enums;

enum TipoMovimientoLineaCredito: string
{
    case AUTORIZACION_INICIAL = 'INITIAL_AUTHORIZATION';
    case INCREASE = 'INCREASE';
    case VOUCHER_CASHED = 'VOUCHER_CASHED';
}
