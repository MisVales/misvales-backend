<?php

namespace App\Modules\Access\Domain\Security;

enum OutboxState: string
{
    case PENDING = 'PENDING';
    case PROCESSED = 'PROCESSED';
    case FAILED = 'FAILED';
}
