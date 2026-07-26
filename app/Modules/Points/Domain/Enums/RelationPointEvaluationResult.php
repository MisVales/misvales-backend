<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Enums;

enum RelationPointEvaluationResult: string
{
    case EARNED = 'EARNED';
    case PENALIZED = 'PENALIZED';
    case NO_CHANGE_PUNCTUAL = 'NO_CHANGE_PUNCTUAL';
    case NO_CHANGE_ZERO_RESULT = 'NO_CHANGE_ZERO_RESULT';
    case WAITING_FOR_LIQUIDATION = 'WAITING_FOR_LIQUIDATION';
    case ALREADY_PROCESSED = 'ALREADY_PROCESSED';
    case BLOCKED = 'BLOCKED';
    case ERROR = 'ERROR';
}
