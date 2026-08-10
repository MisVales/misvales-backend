<?php

namespace App\Enums;

enum DecisionGerencialIncremento: string
{
    case APPROVE_REQUESTED = 'APPROVE_REQUESTED';
    case APPROVE_LOWER = 'APPROVE_LOWER';
    case REJECT = 'REJECT';
}
