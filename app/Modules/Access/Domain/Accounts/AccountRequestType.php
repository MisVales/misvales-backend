<?php

namespace App\Modules\Access\Domain\Accounts;

enum AccountRequestType: string
{
    case CREATE = 'CREATE';
    case DISABLE = 'DISABLE';
    case REACTIVATE = 'REACTIVATE';
    case RECOVERY = 'RECOVERY';
}
