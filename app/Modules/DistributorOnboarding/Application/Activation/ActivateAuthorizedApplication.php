<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Activation;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationTransitioner;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;
use App\Modules\DistributorOnboarding\Application\Support\WorkflowRecorder;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Domain\Contracts\AccountPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\ConfigurationPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\CreditLinePort;
use App\Modules\DistributorOnboarding\Domain\Contracts\DistributorPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\OrganizationPort;
use App\Modules\DistributorOnboarding\Domain\Decisions\ManagerDecision;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Domain\Expedients\NormalizedEmail;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationActivationRecord;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationAuthorization;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use Illuminate\Support\Facades\DB;

/** Ejecuta el aprovisionamiento idempotente con la identidad derivada de la autorización. */
final readonly class ActivateAuthorizedApplication
{
    public function __construct(
        private OnboardingAuthorizer $authorizer,
        private OrganizationPort $organization,
        private DistributorPort $distributors,
        private ConfigurationPort $configuration,
        private CreditLinePort $creditLines,
        private AccountPort $accounts,
        private ApplicationTransitioner $transitioner,
        private WorkflowRecorder $recorder,
    ) {}

    public function execute(
        string $applicationPublicId,
        string $authorizationPublicId,
        ActorContext $actor,
        OperationMetadata $metadata,
    ): ApplicationActivationRecord {
        return DB::transaction(function () use ($applicationPublicId, $authorizationPublicId, $actor, $metadata): ApplicationActivationRecord {
            $application = DistributorApplication::query()
                ->where('public_id', $applicationPublicId)
                ->lockForUpdate()
                ->first();
            if ($application === null) {
                throw OnboardingDomainException::scopeDenied();
            }
            $this->authorizer->assertManagerDecision($actor, $application);

            $authorization = ApplicationAuthorization::query()
                ->where('public_id', $authorizationPublicId)
                ->where('application_id', $application->id)
                ->lockForUpdate()
                ->first();
            if (
                $authorization === null
                || $authorization->decision !== ManagerDecision::APPROVE
            ) {
                throw OnboardingDomainException::invalidState();
            }

            $existing = ApplicationActivationRecord::query()
                ->where('application_id', $application->id)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            if (
                $application->status !== ApplicationStatus::MANAGER_AUTHORIZATION
                || $authorization->application_version !== $application->lock_version
            ) {
                throw OnboardingDomainException::invalidState();
            }

            $operationKey = 'm04-activation:'.$authorization->public_id;
            $email = new NormalizedEmail($application->contact_email);
            $this->accounts->assertEmailAvailable($email->value);
            $this->organization->assertResponsibleCoordinator($application->coordinator_user_id, $application->branch_id);

            $distributor = $this->distributors->provision(
                $application->public_id,
                $application->account_name,
                (int) $application->branch_id,
                $operationKey,
            );
            $assignmentId = $this->organization->createDistributorAssignment(
                $distributor->distributorId,
                (int) $application->coordinator_user_id,
                (int) $application->branch_id,
                $operationKey,
            );
            $tolerance = $this->configuration->firstVoucherTolerance();
            $creditLine = $this->creditLines->openInitialLine(
                $distributor->distributorId,
                (string) $authorization->initial_credit_line,
                $tolerance,
                $operationKey,
            );
            $account = $this->accounts->provisionDistributor(
                $email->value,
                $application->account_name,
                (int) $application->branch_id,
                $operationKey,
            );

            $activation = new ApplicationActivationRecord;
            $activation->forceFill([
                'application_id' => $application->id,
                'authorization_id' => $authorization->id,
                'distributor_id' => $distributor->distributorId,
                'distributor_number' => $distributor->distributorNumber,
                'account_id' => $account->accountId,
                'organization_assignment_id' => $assignmentId,
                'credit_line_id' => $creditLine->creditLineId,
                'initial_movement_id' => $creditLine->initialMovementId,
                'first_voucher_restriction_id' => $creditLine->firstVoucherRestrictionId,
                'initial_credit_line' => $authorization->initial_credit_line,
                'operation_key' => $operationKey,
                'activated_at' => now(),
            ])->save();

            $this->transitioner->transition(
                $application,
                $actor,
                ApplicationAction::ACTIVATE,
                $authorization->reason,
                ManagerDecision::APPROVE->value,
                $metadata,
                'EV-008',
            );
            $this->recorder->mutation(
                $application,
                $actor,
                'EV-010',
                'application_activation',
                $activation->public_id,
                null,
                $metadata,
            );

            return $activation;
        }, 3);
    }
}
