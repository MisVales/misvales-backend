<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Clasificación temporal de una liquidación según su fecha efectiva. */
enum SettlementClassification: string
{
    case UNCLASSIFIED = 'SIN_CLASIFICAR';
    case EARLY = 'ANTICIPADA';
    case ON_TIME = 'PUNTUAL';
    case LATE = 'FUERA_DE_TIEMPO';
}
