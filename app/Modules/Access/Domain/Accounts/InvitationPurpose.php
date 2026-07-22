<?php

namespace App\Modules\Access\Domain\Accounts;

enum InvitationPurpose: string
{
    case ACCOUNT_ACTIVATION = 'ACCOUNT_ACTIVATION';
    case ACCOUNT_REACTIVATION = 'ACCOUNT_REACTIVATION';
    case ACCOUNT_RECOVERY = 'ACCOUNT_RECOVERY';
}
