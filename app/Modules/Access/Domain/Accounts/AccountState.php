<?php

namespace App\Modules\Access\Domain\Accounts;

enum AccountState: string
{
    case PENDING_ACTIVATION = 'PENDING_ACTIVATION';
    case ACTIVE = 'ACTIVE';
    case SECURITY_SUSPENDED = 'SECURITY_SUSPENDED';
    case DISABLED = 'DISABLED';

    public function canAuthenticate(): bool
    {
        return $this === self::ACTIVE;
    }
}
