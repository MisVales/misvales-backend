<?php

namespace App\Modules\Access\Domain\Authentication;

enum TokenState: string
{
    case ACTIVE = 'ACTIVE';
    case USED = 'USED';
    case REPLACED = 'REPLACED';
    case EXPIRED = 'EXPIRED';
    case REVOKED = 'REVOKED';
}
