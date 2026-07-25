<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Expedients;

/** Clases de patrimonio expresamente identificadas por la especificación. */
enum AssetLiabilityType: string
{
    case ASSET = 'ASSET';
    case LOAN = 'LOAN';
    case ACTIVE_COMMITMENT = 'ACTIVE_COMMITMENT';
}
