<?php

namespace App\Modules\Access\Domain\Authentication;

/**
 * Describes the lifecycle state of one-use access tokens.
 */
enum TokenState: string
{
    case ACTIVE = 'ACTIVE';
    case USED = 'USED';
    case REPLACED = 'REPLACED';
    case REVOKED = 'REVOKED';
    case EXPIRED = 'EXPIRED';
}
