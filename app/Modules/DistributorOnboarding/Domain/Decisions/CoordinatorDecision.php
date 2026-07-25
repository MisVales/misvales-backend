<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Decisions;

use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;

/** Decisiones cerradas de la evaluación del coordinador. */
enum CoordinatorDecision: string
{
    case MEETS_REQUIREMENTS = 'MEETS_REQUIREMENTS';
    case DOES_NOT_MEET_REQUIREMENTS = 'DOES_NOT_MEET_REQUIREMENTS';

    public function transitionAction(): ApplicationAction
    {
        return match ($this) {
            self::MEETS_REQUIREMENTS => ApplicationAction::EVALUATE_FAVORABLE,
            self::DOES_NOT_MEET_REQUIREMENTS => ApplicationAction::EVALUATE_UNFAVORABLE,
        };
    }
}
