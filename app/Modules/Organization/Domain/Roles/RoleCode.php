<?php

namespace App\Modules\Organization\Domain\Roles;

enum RoleCode: string
{
    case GENERAL_MANAGER = 'GENERAL_MANAGER';
    case BRANCH_MANAGER = 'BRANCH_MANAGER';
    case COORDINATOR = 'COORDINATOR';
    case VERIFIER = 'VERIFIER';
    case ADMINISTRATOR = 'ADMINISTRATOR';
    case DISTRIBUTOR = 'DISTRIBUTOR';
    case CASHIER = 'CASHIER';
}
