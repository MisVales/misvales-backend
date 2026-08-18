<?php

namespace App\Enums;

enum ApplicationEvaluationResult: string
{
    case COMPLIES = 'COMPLIES';
    case DOES_NOT_COMPLY = 'DOES_NOT_COMPLY';
}
