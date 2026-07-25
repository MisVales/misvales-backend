<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Reviews;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationLocker;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationTransitioner;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationReviewObservation;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use Illuminate\Support\Facades\DB;

/** Devuelve una revisión incompleta sin borrar la entrega ni observaciones anteriores. */
final readonly class RequestDocumentCorrection
{
    public function __construct(
        private ApplicationLocker $locker,
        private OnboardingAuthorizer $authorizer,
        private IdempotencyService $idempotency,
        private ApplicationTransitioner $transitioner,
    ) {}

    public function execute(RequestDocumentCorrectionCommand $command): DistributorApplication
    {
        $payload = [
            'application_id' => $command->applicationPublicId,
            'lock_version' => $command->lockVersion,
            'reason' => $command->reason,
        ];
        $replay = $this->idempotency->replay('REQUEST_DOCUMENT_CORRECTION', $command->metadata->idempotencyKey, $payload);
        if ($replay !== null) {
            return DistributorApplication::query()->where('public_id', $command->applicationPublicId)->firstOrFail();
        }

        return DB::transaction(function () use ($command): DistributorApplication {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->authorizer->assertResponsibleCoordinator(
                $command->actor,
                $application,
                PermissionCode::ONBOARDING_APPLICATIONS_REVIEW,
            );
            $reservation = $this->idempotency->reserve('REQUEST_DOCUMENT_CORRECTION', $command->metadata->idempotencyKey, [
                'application_id' => $application->public_id,
                'lock_version' => $command->lockVersion,
                'reason' => $command->reason,
            ], $application->id);
            if ($reservation->isReplay()) {
                return $application;
            }

            $observation = new ApplicationReviewObservation;
            $observation->forceFill([
                'application_id' => $application->id,
                'observation' => $command->reason,
                'action' => 'REQUEST_DOCUMENT_CORRECTION',
                'coordinator_user_id' => $command->actor->userId,
                'recorded_at' => now(),
            ])->save();

            $this->transitioner->transition(
                $application,
                $command->actor,
                ApplicationAction::REQUEST_DOCUMENT_CORRECTION,
                $command->reason,
                null,
                $command->metadata,
                'M04_DOCUMENT_CORRECTION_REQUESTED',
            );
            $this->idempotency->complete($reservation->record, 'distributor_application', $application->public_id, [
                'application_id' => $application->public_id,
                'lock_version' => $application->lock_version,
            ]);

            return $application->refresh();
        }, 3);
    }
}
