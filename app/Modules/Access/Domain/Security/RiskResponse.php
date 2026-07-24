<?php

namespace App\Modules\Access\Domain\Security;

enum RiskResponse: string
{
    case CONTINUE_AND_RECORD = 'CONTINUE_AND_RECORD';
    case REQUIRE_MFA = 'REQUIRE_MFA';
    case REJECT_AND_REVOKE_SESSION = 'REJECT_AND_REVOKE_SESSION';
    case REVOKE_ALL_AND_OPEN_INCIDENT = 'REVOKE_ALL_AND_OPEN_INCIDENT';
}
