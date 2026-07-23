<?php

namespace App\Modules\Access\Domain\Authorization;

enum RoleCode: string
{
    case GENERAL_MANAGER = 'GENERAL_MANAGER';
    case SUCURSAL_MANAGER = 'SUCURSAL_MANAGER';
    case COORDINATOR = 'COORDINATOR';
    case VERIFIER = 'VERIFIER';
    case ADMINISTRATOR = 'ADMINISTRATOR';
    case DISTRIBUTOR = 'DISTRIBUTOR';
    case CASHIER = 'CASHIER';

    public function isGlobal(): bool
    {
        return in_array($this, [self::GENERAL_MANAGER, self::ADMINISTRATOR], true);
    }
}
