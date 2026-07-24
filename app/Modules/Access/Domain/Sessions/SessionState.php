<?php

namespace App\Modules\Access\Domain\Sessions;

enum SessionState: string
{
    case ACTIVE = 'ACTIVE';
    case EXPIRED = 'EXPIRED';
    case REVOKED = 'REVOKED';
}
