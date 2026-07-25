<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Applications;

/** Operaciones explícitas que pueden producir una transición de estado. */
enum ApplicationAction: string
{
    case CREATE = 'CREATE';
    case SUBMIT = 'SUBMIT';
    case REQUEST_DOCUMENT_CORRECTION = 'REQUEST_DOCUMENT_CORRECTION';
    case ASSIGN_VERIFIER = 'ASSIGN_VERIFIER';
    case START_VISIT = 'START_VISIT';
    case COMPLETE_VISIT_CORRECTABLE = 'COMPLETE_VISIT_CORRECTABLE';
    case COMPLETE_VISIT_FAVORABLE = 'COMPLETE_VISIT_FAVORABLE';
    case COMPLETE_VISIT_UNFAVORABLE = 'COMPLETE_VISIT_UNFAVORABLE';
    case COMPLETE_CORRECTIONS = 'COMPLETE_CORRECTIONS';
    case EVALUATE_FAVORABLE = 'EVALUATE_FAVORABLE';
    case EVALUATE_UNFAVORABLE = 'EVALUATE_UNFAVORABLE';
    case MANAGER_REJECT = 'MANAGER_REJECT';
    case ACTIVATE = 'ACTIVATE';
}
