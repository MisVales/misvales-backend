<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Corrections;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationLocker;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationTransitioner;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;
use App\Modules\DistributorOnboarding\Domain\Contracts\ExpedientRequirementsPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\OrganizationPort;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use Illuminate\Support\Facades\DB;

/** Cierra correcciones sin crear una segunda visita implícita. */
final readonly class CompleteCorrections
{
    public function __construct(
        private ApplicationLocker $locker,
        private OnboardingAuthorizer $authorizer,
        private OrganizationPort $organization,
        private ExpedientRequirementsPort $requirements,
        private IdempotencyService $idempotency,
        private ApplicationTransitioner $transitioner,
    ) {}

    public function execute(CompleteCorrectionsCommand $command): DistributorApplication
    {
        $payload = [
            'application_id' => $command->applicationPublicId,
            'lock_version' => $command->lockVersion,
        ];
        $replay = $this->idempotency->replay('COMPLETE_CORRECTIONS', $command->metadata->idempotencyKey, $payload);
        if ($replay !== null) {
            return DistributorApplication::query()->where('public_id', $command->applicationPublicId)->firstOrFail();
        }

        return DB::transaction(function () use ($command): DistributorApplication {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->authorizer->assertResponsibleCoordinator(
                $command->actor,
                $application,
                PermissionCode::ONBOARDING_APPLICATIONS_CORRECT,
            );
            $this->organization->assertResponsibleCoordinator($application->coordinator_user_id, $application->branch_id);
            if ($application->differences()->whereNull('resolved_at')->exists()) {
                throw OnboardingDomainException::differencesPending();
            }
            $this->requirements->assertComplete($application);

            $reservation = $this->idempotency->reserve('COMPLETE_CORRECTIONS', $command->metadata->idempotencyKey, [
                'application_id' => $application->public_id,
                'lock_version' => $command->lockVersion,
            ], $application->id);
            if ($reservation->isReplay()) {
                return $application;
            }

            $this->transitioner->transition(
                $application,
                $command->actor,
                ApplicationAction::COMPLETE_CORRECTIONS,
                null,
                null,
                $command->metadata,
                'M04_CORRECTIONS_COMPLETED',
            );
            $this->idempotency->complete($reservation->record, 'distributor_application', $application->public_id, [
                'application_id' => $application->public_id,
                'lock_version' => $application->lock_version,
            ]);

            return $application->refresh();
        }, 3);
    }
}
