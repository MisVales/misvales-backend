<?php

namespace App\Modules\Access\Domain\MFA;

enum MfaType: string
{
    case PASSKEY = 'PASSKEY';
    case TOTP = 'TOTP';
}
