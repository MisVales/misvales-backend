<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Applications;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Application\Support\WorkflowRecorder;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Domain\Contracts\FolioGenerator;
use App\Modules\DistributorOnboarding\Domain\Contracts\OrganizationPort;
use App\Modules\DistributorOnboarding\Domain\Expedients\NormalizedEmail;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use Illuminate\Support\Facades\DB;

/** Crea una solicitud sin aceptar sucursal, coordinador, estado o folio del cliente. */
final readonly class CreateApplication
{
    public function __construct(
        private OnboardingAuthorizer $authorizer,
        private OrganizationPort $organization,
        private FolioGenerator $folioGenerator,
        private IdempotencyService $idempotency,
        private WorkflowRecorder $recorder,
    ) {}

    public function execute(CreateApplicationCommand $command): DistributorApplication
    {
        return DB::transaction(function () use ($command): DistributorApplication {
            $this->authorizer->assertPermission($command->actor, PermissionCode::ONBOARDING_APPLICATIONS_CREATE);
            $email = new NormalizedEmail($command->contactEmail);
            $reservation = $this->idempotency->reserve('CREATE_APPLICATION', $command->metadata->idempotencyKey, [
                'actor_id' => $command->actor->userId,
                'contact_email_hash' => $email->protectedHash((string) config('app.key')),
                'account_name' => $command->accountName,
            ]);

            if ($reservation->isReplay()) {
                return DistributorApplication::query()
                    ->where('public_id', $reservation->replayedPayload['application_id'])
                    ->firstOrFail();
            }

            $responsibility = $this->organization->resolveCreationContext($command->actor);
            $this->organization->assertResponsibleCoordinator(
                $responsibility->coordinatorUserId,
                $responsibility->branchId,
            );

            $application = new DistributorApplication;
            $application->forceFill([
                'folio' => $this->folioGenerator->next(),
                'contact_email' => $email->value,
                'normalized_email_hash' => $email->protectedHash((string) config('app.key')),
                'account_name' => trim($command->accountName),
                'branch_id' => $responsibility->branchId,
                'coordinator_user_id' => $responsibility->coordinatorUserId,
                'status' => ApplicationStatus::CAPTURE,
                'lock_version' => 1,
                'created_by' => $command->actor->userId,
            ])->save();

            $this->recorder->transition(
                application: $application,
                actor: $command->actor,
                previous: null,
                next: ApplicationStatus::CAPTURE,
                action: ApplicationAction::CREATE->value,
                reason: null,
                result: null,
                metadata: $command->metadata,
                eventType: 'M04_APPLICATION_CREATED',
            );
            $this->idempotency->complete($reservation->record, 'distributor_application', $application->public_id, [
                'application_id' => $application->public_id,
            ]);

            return $application->refresh();
        }, 3);
    }
}
