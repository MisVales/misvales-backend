<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Applications;

use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Centraliza todas las transiciones permitidas de M04. */
final class ApplicationStateMachine
{
    public function destination(ApplicationStatus $current, ApplicationAction $action): ApplicationStatus
    {
        if ($current->isTerminal()) {
            throw OnboardingDomainException::applicationTerminal();
        }

        return match ([$current, $action]) {
            [ApplicationStatus::CAPTURE, ApplicationAction::SUBMIT] => ApplicationStatus::COORDINATOR_REVIEW,
            [ApplicationStatus::COORDINATOR_REVIEW, ApplicationAction::REQUEST_DOCUMENT_CORRECTION] => ApplicationStatus::CAPTURE,
            [ApplicationStatus::COORDINATOR_REVIEW, ApplicationAction::ASSIGN_VERIFIER] => ApplicationStatus::VISIT_ASSIGNED,
            [ApplicationStatus::VISIT_ASSIGNED, ApplicationAction::START_VISIT] => ApplicationStatus::PHYSICAL_VERIFICATION,
            [ApplicationStatus::PHYSICAL_VERIFICATION, ApplicationAction::COMPLETE_VISIT_CORRECTABLE] => ApplicationStatus::COORDINATOR_CORRECTION,
            [ApplicationStatus::PHYSICAL_VERIFICATION, ApplicationAction::COMPLETE_VISIT_FAVORABLE] => ApplicationStatus::COORDINATOR_EVALUATION,
            [ApplicationStatus::PHYSICAL_VERIFICATION, ApplicationAction::COMPLETE_VISIT_UNFAVORABLE] => ApplicationStatus::TERMINATED_UNFAVORABLE,
            [ApplicationStatus::COORDINATOR_CORRECTION, ApplicationAction::COMPLETE_CORRECTIONS] => ApplicationStatus::COORDINATOR_EVALUATION,
            [ApplicationStatus::COORDINATOR_EVALUATION, ApplicationAction::EVALUATE_FAVORABLE] => ApplicationStatus::MANAGER_AUTHORIZATION,
            [ApplicationStatus::COORDINATOR_EVALUATION, ApplicationAction::EVALUATE_UNFAVORABLE] => ApplicationStatus::TERMINATED_UNFAVORABLE,
            [ApplicationStatus::MANAGER_AUTHORIZATION, ApplicationAction::MANAGER_REJECT] => ApplicationStatus::REJECTED,
            [ApplicationStatus::MANAGER_AUTHORIZATION, ApplicationAction::ACTIVATE] => ApplicationStatus::ACTIVE,
            default => throw OnboardingDomainException::invalidState(),
        };
    }
}
