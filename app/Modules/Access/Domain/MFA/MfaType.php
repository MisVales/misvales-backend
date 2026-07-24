<?php

namespace App\Modules\Access\Domain\MFA;

/**
 * Enumerates the MFA factors supported by M01.
 */
enum MfaType: string
{
    case PASSKEY = 'PASSKEY';
    case TOTP = 'TOTP';
}
