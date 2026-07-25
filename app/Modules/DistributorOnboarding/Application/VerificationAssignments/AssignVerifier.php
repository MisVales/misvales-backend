<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\VerificationAssignments;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationLocker;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationTransitioner;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Application\Support\WorkflowRecorder;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;
use App\Modules\DistributorOnboarding\Domain\Contracts\OrganizationPort;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use App\Modules\DistributorOnboarding\Persistence\Models\VerifierAssignment;
use Illuminate\Support\Facades\DB;

/** Asigna un único verificador vigente después de validar M02 y la autoridad explícita. */
final readonly class AssignVerifier
{
    public function __construct(
        private ApplicationLocker $locker,
        private OnboardingAuthorizer $authorizer,
        private OrganizationPort $organization,
        private IdempotencyService $idempotency,
        private ApplicationTransitioner $transitioner,
        private WorkflowRecorder $recorder,
    ) {}

    public function execute(AssignVerifierCommand $command): DistributorApplication
    {
        $payload = [
            'application_id' => $command->applicationPublicId,
            'verifier_id' => $command->verifierPublicId,
            'lock_version' => $command->lockVersion,
        ];
        $replay = $this->idempotency->replay('ASSIGN_VERIFIER', $command->metadata->idempotencyKey, $payload);
        if ($replay !== null) {
            return DistributorApplication::query()->where('public_id', $command->applicationPublicId)->firstOrFail();
        }

        return DB::transaction(function () use ($command): DistributorApplication {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->authorizer->assertPermission($command->actor, PermissionCode::ONBOARDING_VERIFICATIONS_ASSIGN);
            $this->authorizer->assertCanView($command->actor, $application);
            $verifier = User::query()->where('public_id', $command->verifierPublicId)->first();
            if ($verifier === null) {
                throw OnboardingDomainException::scopeDenied();
            }
            $this->organization->assertVerifier((int) $verifier->id, (int) $application->branch_id);

            $reservation = $this->idempotency->reserve('ASSIGN_VERIFIER', $command->metadata->idempotencyKey, [
                'application_id' => $application->public_id,
                'verifier_id' => $command->verifierPublicId,
                'lock_version' => $command->lockVersion,
            ], $application->id);
            if ($reservation->isReplay()) {
                return $application;
            }
            if ($application->activeVerifierAssignment()->exists()) {
                throw OnboardingDomainException::verifierAssignmentConflict();
            }

            $assignment = new VerifierAssignment;
            $assignment->forceFill([
                'application_id' => $application->id,
                'verifier_user_id' => $verifier->id,
                'branch_id' => $application->branch_id,
                'assigned_by' => $command->actor->userId,
                'assigned_at' => now(),
                'active_slot' => true,
                'lock_version' => 1,
            ])->save();

            $this->recorder->mutation(
                $application,
                $command->actor,
                'EV-001',
                'verifier_assignment',
                $assignment->public_id,
                null,
                $command->metadata,
            );
            $this->transitioner->transition(
                $application,
                $command->actor,
                ApplicationAction::ASSIGN_VERIFIER,
                null,
                null,
                $command->metadata,
                'EV-002',
            );
            $this->idempotency->complete($reservation->record, 'verifier_assignment', $assignment->public_id, [
                'application_id' => $application->public_id,
                'assignment_id' => $assignment->public_id,
                'lock_version' => $application->lock_version,
            ]);

            return $application->refresh();
        }, 3);
    }
}
