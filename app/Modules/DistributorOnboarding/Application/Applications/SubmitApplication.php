<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Applications;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationLocker;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationSnapshotHasher;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationTransitioner;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;
use App\Modules\DistributorOnboarding\Domain\Contracts\ExpedientRequirementsPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\OrganizationPort;
use App\Modules\DistributorOnboarding\Domain\Expedients\NormalizedEmail;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationSubmission;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use Illuminate\Support\Facades\DB;

/** Valida la matriz aprobada y conserva una huella de la versión enviada. */
final readonly class SubmitApplication
{
    public function __construct(
        private ApplicationLocker $locker,
        private OnboardingAuthorizer $authorizer,
        private ExpedientRequirementsPort $requirements,
        private OrganizationPort $organization,
        private ApplicationSnapshotHasher $snapshotHasher,
        private IdempotencyService $idempotency,
        private ApplicationTransitioner $transitioner,
    ) {}

    public function execute(SubmitApplicationCommand $command): DistributorApplication
    {
        $payload = [
            'application_id' => $command->applicationPublicId,
            'lock_version' => $command->lockVersion,
        ];
        $replay = $this->idempotency->replay('SUBMIT_APPLICATION', $command->metadata->idempotencyKey, $payload);
        if ($replay !== null) {
            return DistributorApplication::query()->where('public_id', $command->applicationPublicId)->firstOrFail();
        }

        return DB::transaction(function () use ($command): DistributorApplication {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->authorizer->assertPermission($command->actor, PermissionCode::ONBOARDING_APPLICATIONS_SUBMIT);
            $this->authorizer->assertCanView($command->actor, $application);
            new NormalizedEmail($application->contact_email);
            $this->organization->assertResponsibleCoordinator($application->coordinator_user_id, $application->branch_id);
            $this->requirements->assertComplete($application);

            $reservation = $this->idempotency->reserve('SUBMIT_APPLICATION', $command->metadata->idempotencyKey, [
                'application_id' => $command->applicationPublicId,
                'lock_version' => $command->lockVersion,
            ], $application->id);
            if ($reservation->isReplay()) {
                return $application;
            }

            $snapshotHash = $this->snapshotHasher->hash($application);

            $this->transitioner->transition(
                $application,
                $command->actor,
                ApplicationAction::SUBMIT,
                null,
                null,
                $command->metadata,
                'M04_APPLICATION_SUBMITTED',
            );

            $submission = new ApplicationSubmission;
            $submission->forceFill([
                'application_id' => $application->id,
                'application_version' => $application->lock_version,
                'snapshot_hash' => $snapshotHash,
                'submitted_by' => $command->actor->userId,
                'submitted_at' => now(),
                'idempotency_key' => $command->metadata->idempotencyKey,
            ])->save();

            $application->forceFill([
                'submitted_by' => $command->actor->userId,
                'submitted_at' => now(),
            ])->save();
            $this->idempotency->complete($reservation->record, 'distributor_application', $application->public_id, [
                'application_id' => $application->public_id,
                'lock_version' => $application->lock_version,
            ]);

            return $application->refresh();
        }, 3);
    }
}
