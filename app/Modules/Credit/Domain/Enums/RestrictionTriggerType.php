<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Enums;

enum RestrictionTriggerType: string
{
    case INITIAL_AUTHORIZATION = 'INITIAL_AUTHORIZATION';
    case INCREASE = 'INCREASE';
}
