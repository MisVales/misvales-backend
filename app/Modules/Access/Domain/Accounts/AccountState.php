<?php

namespace App\Modules\Access\Domain\Accounts;

/**
 * Lists the persistent account states allowed by M01.
 */
enum AccountState: string
{
    case PENDING_ACTIVATION = 'PENDING_ACTIVATION';
    case ACTIVE = 'ACTIVE';
    case SECURITY_SUSPENDED = 'SECURITY_SUSPENDED';
    case DISABLED = 'DISABLED';
}
