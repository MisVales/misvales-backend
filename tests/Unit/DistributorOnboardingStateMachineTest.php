<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStateMachine;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DistributorOnboardingStateMachineTest extends TestCase
{
    /** @return iterable<string, array{ApplicationStatus, ApplicationAction, ApplicationStatus}> */
    public static function transitions(): iterable
    {
        yield 'submit' => [ApplicationStatus::CAPTURE, ApplicationAction::SUBMIT, ApplicationStatus::COORDINATOR_REVIEW];
        yield 'document correction' => [ApplicationStatus::COORDINATOR_REVIEW, ApplicationAction::REQUEST_DOCUMENT_CORRECTION, ApplicationStatus::CAPTURE];
        yield 'assign verifier' => [ApplicationStatus::COORDINATOR_REVIEW, ApplicationAction::ASSIGN_VERIFIER, ApplicationStatus::VISIT_ASSIGNED];
        yield 'start visit' => [ApplicationStatus::VISIT_ASSIGNED, ApplicationAction::START_VISIT, ApplicationStatus::PHYSICAL_VERIFICATION];
        yield 'correctable visit' => [ApplicationStatus::PHYSICAL_VERIFICATION, ApplicationAction::COMPLETE_VISIT_CORRECTABLE, ApplicationStatus::COORDINATOR_CORRECTION];
        yield 'favorable visit' => [ApplicationStatus::PHYSICAL_VERIFICATION, ApplicationAction::COMPLETE_VISIT_FAVORABLE, ApplicationStatus::COORDINATOR_EVALUATION];
        yield 'unfavorable visit' => [ApplicationStatus::PHYSICAL_VERIFICATION, ApplicationAction::COMPLETE_VISIT_UNFAVORABLE, ApplicationStatus::TERMINATED_UNFAVORABLE];
        yield 'corrections completed' => [ApplicationStatus::COORDINATOR_CORRECTION, ApplicationAction::COMPLETE_CORRECTIONS, ApplicationStatus::COORDINATOR_EVALUATION];
        yield 'favorable evaluation' => [ApplicationStatus::COORDINATOR_EVALUATION, ApplicationAction::EVALUATE_FAVORABLE, ApplicationStatus::MANAGER_AUTHORIZATION];
        yield 'unfavorable evaluation' => [ApplicationStatus::COORDINATOR_EVALUATION, ApplicationAction::EVALUATE_UNFAVORABLE, ApplicationStatus::TERMINATED_UNFAVORABLE];
        yield 'manager rejection' => [ApplicationStatus::MANAGER_AUTHORIZATION, ApplicationAction::MANAGER_REJECT, ApplicationStatus::REJECTED];
        yield 'activation' => [ApplicationStatus::MANAGER_AUTHORIZATION, ApplicationAction::ACTIVATE, ApplicationStatus::ACTIVE];
    }

    #[DataProvider('transitions')]
    public function test_only_declared_transitions_are_resolved(
        ApplicationStatus $from,
        ApplicationAction $action,
        ApplicationStatus $to,
    ): void {
        self::assertSame($to, (new ApplicationStateMachine)->destination($from, $action));
    }

    public function test_a_state_cannot_be_skipped(): void
    {
        $this->expectException(OnboardingDomainException::class);
        $this->expectExceptionMessage('estado compatible');

        (new ApplicationStateMachine)->destination(ApplicationStatus::CAPTURE, ApplicationAction::ACTIVATE);
    }

    /** @return iterable<string, array{ApplicationStatus}> */
    public static function terminalStates(): iterable
    {
        yield 'unfavorable' => [ApplicationStatus::TERMINATED_UNFAVORABLE];
        yield 'rejected' => [ApplicationStatus::REJECTED];
        yield 'active' => [ApplicationStatus::ACTIVE];
    }

    #[DataProvider('terminalStates')]
    public function test_terminal_states_cannot_be_reopened(ApplicationStatus $status): void
    {
        $this->expectException(OnboardingDomainException::class);
        $this->expectExceptionMessage('terminó');

        (new ApplicationStateMachine)->destination($status, ApplicationAction::SUBMIT);
    }
}
