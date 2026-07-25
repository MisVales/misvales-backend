<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Visits;

use App\Modules\DistributorOnboarding\Application\Support\ApplicationLocker;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationTransitioner;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Application\Support\WorkflowRecorder;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Domain\Verification\VisitResult;
use App\Modules\DistributorOnboarding\Persistence\Models\VerificationVisit;
use Illuminate\Support\Facades\DB;

/** Finaliza una visita una sola vez y aplica el destino definido por su resultado. */
final readonly class CompleteVisit
{
    public function __construct(
        private ApplicationLocker $locker,
        private OnboardingAuthorizer $authorizer,
        private IdempotencyService $idempotency,
        private ApplicationTransitioner $transitioner,
        private WorkflowRecorder $recorder,
    ) {}

    public function execute(CompleteVisitCommand $command): VerificationVisit
    {
        $payload = [
            'application_id' => $command->applicationPublicId,
            'visit_id' => $command->visitPublicId,
            'lock_version' => $command->lockVersion,
            'result' => $command->result->value,
            'observations' => $command->observations,
        ];
        $replay = $this->idempotency->replay('COMPLETE_VISIT', $command->metadata->idempotencyKey, $payload);
        if ($replay !== null) {
            return VerificationVisit::query()->where('public_id', $replay['visit_id'])->firstOrFail();
        }

        return DB::transaction(function () use ($command): VerificationVisit {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->authorizer->assertAssignedVerifier($command->actor, $application);
            $visit = VerificationVisit::query()
                ->where('public_id', $command->visitPublicId)
                ->where('application_id', $application->id)
                ->lockForUpdate()
                ->first();
            if ($visit === null) {
                throw OnboardingDomainException::scopeDenied();
            }
            if ($visit->completed_at !== null) {
                throw OnboardingDomainException::visitAlreadyCompleted();
            }
            if (
                $command->result === VisitResult::CORRECTABLE_DIFFERENCES
                && ! $application->differences()->exists()
            ) {
                throw OnboardingDomainException::incomplete();
            }

            $reservation = $this->idempotency->reserve('COMPLETE_VISIT', $command->metadata->idempotencyKey, [
                'application_id' => $application->public_id,
                'visit_id' => $visit->public_id,
                'lock_version' => $command->lockVersion,
                'result' => $command->result->value,
                'observations' => $command->observations,
            ], $application->id);
            if ($reservation->isReplay()) {
                return $visit;
            }

            $visit->forceFill([
                'completed_at' => now(),
                'result' => $command->result,
                'observations' => $command->observations,
                'lock_version' => $visit->lock_version + 1,
            ])->save();

            $eventType = $command->result === VisitResult::UNFAVORABLE ? 'EV-006' : 'EV-003';
            if ($command->result === VisitResult::UNFAVORABLE) {
                $this->recorder->mutation(
                    $application,
                    $command->actor,
                    'EV-003',
                    'verification_visit',
                    $visit->public_id,
                    $command->observations,
                    $command->metadata,
                );
            }
            $this->transitioner->transition(
                $application,
                $command->actor,
                $command->result->transitionAction(),
                $command->observations,
                $command->result->value,
                $command->metadata,
                $eventType,
            );
            if ($command->result === VisitResult::CORRECTABLE_DIFFERENCES) {
                $this->recorder->mutation(
                    $application,
                    $command->actor,
                    'EV-004',
                    'verification_visit',
                    $visit->public_id,
                    $command->observations,
                    $command->metadata,
                );
            }
            $this->idempotency->complete($reservation->record, 'verification_visit', $visit->public_id, [
                'visit_id' => $visit->public_id,
                'application_id' => $application->public_id,
                'lock_version' => $application->lock_version,
            ]);

            return $visit->refresh();
        }, 3);
    }
}
