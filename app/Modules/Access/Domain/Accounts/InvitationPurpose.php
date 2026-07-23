<?php

namespace App\Modules\Access\Domain\Accounts;

/**
 * Identifies the only invitation flows accepted by the access module.
 */
enum InvitationPurpose: string
{
    case ACCOUNT_ACTIVATION = 'ACCOUNT_ACTIVATION';
    case ACCOUNT_REACTIVATION = 'ACCOUNT_REACTIVATION';
    case PASSWORD_RECOVERY = 'PASSWORD_RECOVERY';
    case ACCOUNT_RECOVERY = 'ACCOUNT_RECOVERY';
}
