<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Verification;

use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;

/** Resultados cerrados de la visita física. */
enum VisitResult: string
{
    case FAVORABLE = 'FAVORABLE';
    case UNFAVORABLE = 'UNFAVORABLE';
    case CORRECTABLE_DIFFERENCES = 'CORRECTABLE_DIFFERENCES';

    public function transitionAction(): ApplicationAction
    {
        return match ($this) {
            self::FAVORABLE => ApplicationAction::COMPLETE_VISIT_FAVORABLE,
            self::UNFAVORABLE => ApplicationAction::COMPLETE_VISIT_UNFAVORABLE,
            self::CORRECTABLE_DIFFERENCES => ApplicationAction::COMPLETE_VISIT_CORRECTABLE,
        };
    }
}
