<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Authorizations;

use App\Modules\DistributorOnboarding\Application\Activation\ActivateAuthorizedApplication;
use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationLocker;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationTransitioner;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyReservation;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;
use App\Modules\DistributorOnboarding\Application\Support\WorkflowRecorder;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Domain\Contracts\AccountPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\ConfigurationPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\OrganizationPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\ReauthenticationPort;
use App\Modules\DistributorOnboarding\Domain\Decisions\CoordinatorDecision;
use App\Modules\DistributorOnboarding\Domain\Decisions\InitialCreditLine;
use App\Modules\DistributorOnboarding\Domain\Decisions\ManagerDecision;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Domain\Expedients\NormalizedEmail;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationAuthorization;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Registra la decisión gerencial y activa solo una aprobación completamente aprovisionada. */
final readonly class RecordManagerDecision
{
    public function __construct(
        private ApplicationLocker $locker,
        private OnboardingAuthorizer $authorizer,
        private ReauthenticationPort $reauthentication,
        private AccountPort $accounts,
        private OrganizationPort $organization,
        private ConfigurationPort $configuration,
        private IdempotencyService $idempotency,
        private ApplicationTransitioner $transitioner,
        private WorkflowRecorder $recorder,
        private ActivateAuthorizedApplication $activation,
    ) {}

    public function execute(RecordManagerDecisionCommand $command): DistributorApplication
    {
        $replay = $this->idempotency->replay(
            'MANAGER_DECISION',
            $command->metadata->idempotencyKey,
            $this->decisionPayload($command),
        );
        if ($replay !== null) {
            if ($command->decision === ManagerDecision::APPROVE) {
                $this->recordActivationEvent(
                    $command->applicationPublicId,
                    (string) $replay['authorization_id'],
                    $command->actor,
                    $command->metadata,
                    'M04_ACTIVATION_RETRIED',
                );
                $this->activate(
                    $command->applicationPublicId,
                    (string) $replay['authorization_id'],
                    $command,
                );
            }

            return DistributorApplication::query()->where('public_id', $command->applicationPublicId)->firstOrFail();
        }

        if ($command->decision === ManagerDecision::REJECT) {
            return $this->reject($command);
        }

        $authorization = $this->recordApproval($command);
        $this->activate(
            $command->applicationPublicId,
            $authorization->public_id,
            $command,
        );

        return DistributorApplication::query()->where('public_id', $command->applicationPublicId)->firstOrFail();
    }

    private function reject(RecordManagerDecisionCommand $command): DistributorApplication
    {
        return DB::transaction(function () use ($command): DistributorApplication {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->assertDecisionContext($command, $application);
            if ($command->initialCreditLine !== null) {
                throw OnboardingDomainException::invalidInitialCreditLine();
            }

            $reservation = $this->reserveDecision($command, $application);
            if ($reservation->isReplay()) {
                return $application;
            }

            $authorization = $this->createAuthorization($command, $application, null);
            $this->transitioner->transition(
                $application,
                $command->actor,
                ApplicationAction::MANAGER_REJECT,
                $command->reason,
                ManagerDecision::REJECT->value,
                $command->metadata,
                'EV-009',
            );
            $this->idempotency->complete($reservation->record, 'application_authorization', $authorization->public_id, [
                'authorization_id' => $authorization->public_id,
                'application_id' => $application->public_id,
                'lock_version' => $application->lock_version,
            ]);

            return $application->refresh();
        }, 3);
    }

    private function recordApproval(RecordManagerDecisionCommand $command): ApplicationAuthorization
    {
        return DB::transaction(function () use ($command): ApplicationAuthorization {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->assertDecisionContext($command, $application);
            if ($command->initialCreditLine === null) {
                throw OnboardingDomainException::invalidInitialCreditLine();
            }
            $creditLine = new InitialCreditLine($command->initialCreditLine);
            $this->configuration->assertInitialCreditLineAllowed($creditLine->value);

            $reservation = $this->reserveDecision($command, $application);
            if ($reservation->isReplay()) {
                return ApplicationAuthorization::query()
                    ->where('public_id', $reservation->replayedPayload['authorization_id'])
                    ->firstOrFail();
            }

            if ($command->reauthenticationToken === null) {
                throw OnboardingDomainException::reauthenticationRequired();
            }
            $this->reauthentication->consume(
                $command->actor->userId,
                $application->public_id,
                $command->reauthenticationToken,
            );
            $email = new NormalizedEmail($application->contact_email);
            $this->accounts->assertEmailAvailable($email->value);
            $this->organization->assertResponsibleCoordinator($application->coordinator_user_id, $application->branch_id);

            $authorization = $this->createAuthorization($command, $application, $creditLine->value);
            $this->recorder->mutation(
                $application,
                $command->actor,
                'M04_MANAGER_APPROVAL_RECORDED',
                'application_authorization',
                $authorization->public_id,
                $command->reason,
                $command->metadata,
            );
            $this->idempotency->complete($reservation->record, 'application_authorization', $authorization->public_id, [
                'authorization_id' => $authorization->public_id,
                'application_id' => $application->public_id,
                'lock_version' => $application->lock_version,
            ]);

            return $authorization;
        }, 3);
    }

    private function assertDecisionContext(
        RecordManagerDecisionCommand $command,
        DistributorApplication $application,
    ): void {
        $this->authorizer->assertManagerDecision($command->actor, $application);
        if ($application->status !== ApplicationStatus::MANAGER_AUTHORIZATION) {
            throw OnboardingDomainException::invalidState();
        }
        if ($application->authorization()->exists()) {
            throw OnboardingDomainException::managerDecisionAlreadyRecorded();
        }
        $evaluation = $application->evaluation()->first();
        if (
            $evaluation === null
            || $evaluation->decision !== CoordinatorDecision::MEETS_REQUIREMENTS
            || $evaluation->application_version !== $application->lock_version
        ) {
            throw OnboardingDomainException::invalidState();
        }
    }

    private function reserveDecision(
        RecordManagerDecisionCommand $command,
        DistributorApplication $application,
    ): IdempotencyReservation {
        return $this->idempotency->reserve(
            'MANAGER_DECISION',
            $command->metadata->idempotencyKey,
            $this->decisionPayload($command),
            $application->id,
        );
    }

    /** @return array<string, mixed> */
    private function decisionPayload(RecordManagerDecisionCommand $command): array
    {
        return [
            'application_id' => $command->applicationPublicId,
            'lock_version' => $command->lockVersion,
            'decision' => $command->decision->value,
            'initial_credit_line' => $command->initialCreditLine,
            'reason' => $command->reason,
            'reauthentication_hash' => $command->reauthenticationToken === null ? null : hash('sha256', $command->reauthenticationToken),
        ];
    }

    private function createAuthorization(
        RecordManagerDecisionCommand $command,
        DistributorApplication $application,
        ?string $creditLine,
    ): ApplicationAuthorization {
        $authorization = new ApplicationAuthorization;
        $authorization->forceFill([
            'application_id' => $application->id,
            'decision' => $command->decision,
            'initial_credit_line' => $creditLine,
            'reason' => $command->reason,
            'manager_user_id' => $command->actor->userId,
            'manager_role' => $command->actor->role->value,
            'manager_branch_id' => $command->actor->branchId,
            'application_version' => $application->lock_version,
            'decided_at' => now(),
            'idempotency_key' => $command->metadata->idempotencyKey,
        ])->save();

        return $authorization;
    }

    private function activate(
        string $applicationPublicId,
        string $authorizationPublicId,
        RecordManagerDecisionCommand $command,
    ): void {
        try {
            $this->activation->execute(
                $applicationPublicId,
                $authorizationPublicId,
                $command->actor,
                $command->metadata,
            );
        } catch (Throwable $failure) {
            $this->recordActivationEvent(
                $applicationPublicId,
                $authorizationPublicId,
                $command->actor,
                $command->metadata,
                'M04_ACTIVATION_FAILED',
            );

            throw $failure;
        }
    }

    private function recordActivationEvent(
        string $applicationPublicId,
        string $authorizationPublicId,
        ActorContext $actor,
        OperationMetadata $metadata,
        string $eventType,
    ): void {
        try {
            DB::transaction(function () use ($applicationPublicId, $authorizationPublicId, $actor, $metadata, $eventType): void {
                $application = DistributorApplication::query()
                    ->where('public_id', $applicationPublicId)
                    ->lockForUpdate()
                    ->first();
                if ($application === null) {
                    return;
                }

                $attemptMetadata = new OperationMetadata(
                    idempotencyKey: $metadata->idempotencyKey.':'.$metadata->requestId,
                    requestId: $metadata->requestId,
                    traceId: $metadata->traceId,
                    ipAddress: $metadata->ipAddress,
                    device: $metadata->device,
                    authSessionId: $metadata->authSessionId,
                );
                $this->recorder->mutation(
                    $application,
                    $actor,
                    $eventType,
                    'application_authorization',
                    $authorizationPublicId,
                    null,
                    $attemptMetadata,
                );
            }, 3);
        } catch (Throwable $auditFailure) {
            Log::error('M04 activation-attempt audit failed.', [
                'failure_class' => $auditFailure::class,
                'event_type' => $eventType,
            ]);
        }
    }
}
