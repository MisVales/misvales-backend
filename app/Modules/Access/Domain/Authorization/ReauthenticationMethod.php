<?php

namespace App\Modules\Access\Domain\Authorization;

enum ReauthenticationMethod: string
{
    case PASSWORD_TOTP = 'PASSWORD_TOTP';
    case PASSKEY = 'PASSKEY';
}
