<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Support;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Domain\Contracts\NotificationOutboxPort;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationAudit;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationStatusHistory;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use Carbon\CarbonImmutable;

/** Registra transición, auditoría y outbox dentro de la transacción principal. */
final class WorkflowRecorder
{
    public function __construct(private readonly NotificationOutboxPort $outbox) {}

    public function mutation(
        DistributorApplication $application,
        ActorContext $actor,
        string $eventType,
        string $entityType,
        ?string $entityPublicId,
        ?string $reason,
        OperationMetadata $metadata,
    ): void {
        $now = CarbonImmutable::now('UTC');
        $audit = new ApplicationAudit;
        $audit->forceFill([
            'application_id' => $application->id,
            'event_type' => $eventType,
            'requester_user_id' => $actor->userId,
            'executor_user_id' => $actor->userId,
            'auth_session_id' => $metadata->authSessionId,
            'actor_role' => $actor->role->value,
            'branch_id' => $application->branch_id,
            'application_folio' => $application->folio,
            'entity_type' => $entityType,
            'entity_public_id' => $entityPublicId,
            'reason' => $reason,
            'application_version' => $application->lock_version,
            'request_id' => $metadata->requestId,
            'trace_id' => $metadata->traceId,
            'ip_hash' => $metadata->ipAddress === null ? null : hash_hmac('sha256', $metadata->ipAddress, (string) config('app.key')),
            'device_hash' => $metadata->device === null ? null : hash_hmac('sha256', $metadata->device, (string) config('app.key')),
            'occurred_at' => $now,
            'business_occurred_at' => $now->setTimezone('America/Monterrey'),
        ])->save();

        $this->outbox->publish(
            eventType: $eventType,
            payload: [
                'application_id' => $application->public_id,
                'folio' => $application->folio,
                'entity_type' => $entityType,
                'entity_id' => $entityPublicId,
                'branch_id' => (string) $application->branch_id,
                'actor_id' => (string) $actor->userId,
                'request_id' => $metadata->requestId,
            ],
            idempotencyKey: 'm04:'.hash('sha256', $application->public_id.':'.$metadata->idempotencyKey.':'.$eventType),
            availableAt: $now,
        );
    }

    public function transition(
        DistributorApplication $application,
        ActorContext $actor,
        ?ApplicationStatus $previous,
        ApplicationStatus $next,
        string $action,
        ?string $reason,
        ?string $result,
        OperationMetadata $metadata,
        string $eventType,
    ): void {
        $now = CarbonImmutable::now('UTC');
        $operationKey = 'm04:'.hash('sha256', $action.':'.$metadata->idempotencyKey);

        $history = new ApplicationStatusHistory;
        $history->forceFill([
            'application_id' => $application->id,
            'action' => $action,
            'previous_status' => $previous,
            'new_status' => $next,
            'actor_user_id' => $actor->userId,
            'actor_role' => $actor->role->value,
            'branch_id' => $actor->branchId,
            'reason' => $reason,
            'result' => $result,
            'application_version' => $application->lock_version,
            'idempotency_key' => $operationKey,
            'request_id' => $metadata->requestId,
            'occurred_at' => $now,
        ])->save();

        $audit = new ApplicationAudit;
        $audit->forceFill([
            'application_id' => $application->id,
            'event_type' => $eventType,
            'requester_user_id' => $actor->userId,
            'authorizer_user_id' => str_contains($action, 'MANAGER') || $action === 'ACTIVATE' ? $actor->userId : null,
            'executor_user_id' => $actor->userId,
            'auth_session_id' => $metadata->authSessionId,
            'actor_role' => $actor->role->value,
            'branch_id' => $application->branch_id,
            'application_folio' => $application->folio,
            'entity_type' => 'distributor_application',
            'entity_public_id' => $application->public_id,
            'previous_status' => $previous,
            'new_status' => $next,
            'reason' => $reason,
            'result' => $result,
            'application_version' => $application->lock_version,
            'request_id' => $metadata->requestId,
            'trace_id' => $metadata->traceId,
            'ip_hash' => $metadata->ipAddress === null ? null : hash_hmac('sha256', $metadata->ipAddress, (string) config('app.key')),
            'device_hash' => $metadata->device === null ? null : hash_hmac('sha256', $metadata->device, (string) config('app.key')),
            'occurred_at' => $now,
            'business_occurred_at' => $now->setTimezone('America/Monterrey'),
        ])->save();

        $this->outbox->publish(
            eventType: $eventType,
            payload: [
                'application_id' => $application->public_id,
                'folio' => $application->folio,
                'previous_status' => $previous?->value,
                'new_status' => $next->value,
                'branch_id' => (string) $application->branch_id,
                'actor_id' => (string) $actor->userId,
                'request_id' => $metadata->requestId,
            ],
            idempotencyKey: 'm04:'.hash('sha256', $application->public_id.':'.$operationKey.':'.$eventType),
            availableAt: $now,
        );
    }
}
