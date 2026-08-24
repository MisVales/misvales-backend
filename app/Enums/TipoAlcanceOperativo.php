<?php

namespace App\Enums;

enum TipoAlcanceOperativo: string
{
    case PERSONAL = 'PERSONAL';
    case SUCURSAL = 'BRANCH';
    case GLOBAL = 'GLOBAL';
}
