<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Visits;

use App\Modules\DistributorOnboarding\Application\Support\ApplicationLocker;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationTransitioner;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Persistence\Models\VerificationVisit;
use Illuminate\Support\Facades\DB;

/** Inicia una sola visita para la asignación vigente. */
final readonly class StartVisit
{
    public function __construct(
        private ApplicationLocker $locker,
        private OnboardingAuthorizer $authorizer,
        private IdempotencyService $idempotency,
        private ApplicationTransitioner $transitioner,
    ) {}

    public function execute(StartVisitCommand $command): VerificationVisit
    {
        $payload = [
            'application_id' => $command->applicationPublicId,
            'lock_version' => $command->lockVersion,
        ];
        $replay = $this->idempotency->replay('START_VISIT', $command->metadata->idempotencyKey, $payload);
        if ($replay !== null) {
            return VerificationVisit::query()->where('public_id', $replay['visit_id'])->firstOrFail();
        }

        return DB::transaction(function () use ($command): VerificationVisit {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->authorizer->assertAssignedVerifier($command->actor, $application);
            $assignment = $application->activeVerifierAssignment()->lockForUpdate()->first();
            if ($assignment === null) {
                throw OnboardingDomainException::verifierRequired();
            }

            $reservation = $this->idempotency->reserve('START_VISIT', $command->metadata->idempotencyKey, [
                'application_id' => $command->applicationPublicId,
                'lock_version' => $command->lockVersion,
            ], $application->id);
            if ($reservation->isReplay()) {
                return VerificationVisit::query()->where('public_id', $reservation->replayedPayload['visit_id'])->firstOrFail();
            }
            if (VerificationVisit::query()->where('assignment_id', $assignment->id)->exists()) {
                throw OnboardingDomainException::visitAlreadyStarted();
            }

            $visit = new VerificationVisit;
            $visit->forceFill([
                'application_id' => $application->id,
                'assignment_id' => $assignment->id,
                'verifier_user_id' => $command->actor->userId,
                'started_at' => now(),
                'auth_session_public_id' => $command->authSessionPublicId,
                'device_context' => $command->metadata->device,
                'lock_version' => 1,
            ])->save();

            $this->transitioner->transition(
                $application,
                $command->actor,
                ApplicationAction::START_VISIT,
                null,
                null,
                $command->metadata,
                'M04_VISIT_STARTED',
            );
            $this->idempotency->complete($reservation->record, 'verification_visit', $visit->public_id, [
                'application_id' => $application->public_id,
                'visit_id' => $visit->public_id,
                'lock_version' => $application->lock_version,
            ]);

            return $visit->refresh();
        }, 3);
    }
}
