<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Evaluations;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationLocker;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationTransitioner;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Application\Support\WorkflowRecorder;
use App\Modules\DistributorOnboarding\Domain\Contracts\OrganizationPort;
use App\Modules\DistributorOnboarding\Domain\Decisions\CoordinatorDecision;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Domain\Verification\VisitResult;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationEvaluation;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use Illuminate\Support\Facades\DB;

/** Registra una sola evaluación y termina inmediatamente cuando es desfavorable. */
final readonly class RecordCoordinatorDecision
{
    public function __construct(
        private ApplicationLocker $locker,
        private OnboardingAuthorizer $authorizer,
        private OrganizationPort $organization,
        private IdempotencyService $idempotency,
        private ApplicationTransitioner $transitioner,
        private WorkflowRecorder $recorder,
    ) {}

    public function execute(RecordCoordinatorDecisionCommand $command): DistributorApplication
    {
        $payload = [
            'application_id' => $command->applicationPublicId,
            'lock_version' => $command->lockVersion,
            'decision' => $command->decision->value,
            'reason' => $command->reason,
        ];
        $replay = $this->idempotency->replay('COORDINATOR_DECISION', $command->metadata->idempotencyKey, $payload);
        if ($replay !== null) {
            return DistributorApplication::query()->where('public_id', $command->applicationPublicId)->firstOrFail();
        }

        return DB::transaction(function () use ($command): DistributorApplication {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->authorizer->assertResponsibleCoordinator(
                $command->actor,
                $application,
                PermissionCode::ONBOARDING_APPLICATIONS_EVALUATE,
            );
            $this->organization->assertResponsibleCoordinator($application->coordinator_user_id, $application->branch_id);
            if ($application->evaluation()->exists()) {
                throw OnboardingDomainException::evaluationAlreadyRecorded();
            }
            $visit = $application->visit()->lockForUpdate()->first();
            if (
                $visit === null
                || $visit->completed_at === null
                || $visit->result === VisitResult::UNFAVORABLE
            ) {
                throw OnboardingDomainException::invalidState();
            }
            if ($application->differences()->whereNull('resolved_at')->exists()) {
                throw OnboardingDomainException::differencesPending();
            }

            $reservation = $this->idempotency->reserve('COORDINATOR_DECISION', $command->metadata->idempotencyKey, [
                'application_id' => $application->public_id,
                'lock_version' => $command->lockVersion,
                'decision' => $command->decision->value,
                'reason' => $command->reason,
            ], $application->id);
            if ($reservation->isReplay()) {
                return $application;
            }

            $evaluation = new ApplicationEvaluation;
            $evaluation->forceFill([
                'application_id' => $application->id,
                'coordinator_user_id' => $command->actor->userId,
                'decision' => $command->decision,
                'reason' => $command->reason,
                'branch_id' => $application->branch_id,
                'visit_id' => $visit->id,
                'application_version' => $application->lock_version + 1,
                'decided_at' => now(),
            ])->save();

            if ($command->decision === CoordinatorDecision::MEETS_REQUIREMENTS) {
                $this->recorder->mutation(
                    $application,
                    $command->actor,
                    'EV-005',
                    'application_evaluation',
                    $evaluation->public_id,
                    $command->reason,
                    $command->metadata,
                );
            }

            $eventType = $command->decision === CoordinatorDecision::MEETS_REQUIREMENTS ? 'EV-007' : 'EV-006';
            $this->transitioner->transition(
                $application,
                $command->actor,
                $command->decision->transitionAction(),
                $command->reason,
                $command->decision->value,
                $command->metadata,
                $eventType,
            );
            $this->idempotency->complete($reservation->record, 'application_evaluation', $evaluation->public_id, [
                'evaluation_id' => $evaluation->public_id,
                'application_id' => $application->public_id,
                'lock_version' => $application->lock_version,
            ]);

            return $application->refresh();
        }, 3);
    }
}
