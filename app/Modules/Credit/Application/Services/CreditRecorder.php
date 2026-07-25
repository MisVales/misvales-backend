<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Services;

use App\Models\User;
use App\Modules\Access\Application\Accounts\AccountSecurityRecorder;
use App\Modules\Access\Application\Security\OutboxDispatcher;
use App\Modules\Credit\Domain\Events\CreditDomainEvent;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditAuditEventModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class CreditRecorder
{
    public function __construct(
        private AccountSecurityRecorder $accessRecorder,
        private OutboxDispatcher $outbox,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function audit(
        string $eventType,
        string $result,
        ?User $actor,
        ?int $distributorId,
        ?int $branchId,
        ?string $resourceType,
        ?string $resourceId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
        ?int $reviewerId = null,
        ?int $authorizerId = null,
    ): CreditAuditEventModel {
        $request = app()->bound('request') ? request() : null;
        $token = $actor?->currentAccessToken();
        $sessionId = $token instanceof PersonalAccessToken ? (string) $token->auth_session_id : null;
        $correlationId = $request?->attributes->get('correlation_id');
        $correlationId = is_string($correlationId) && Str::isUuid($correlationId)
            ? $correlationId
            : (string) Str::uuid();

        $event = CreditAuditEventModel::query()->create([
            'actor_user_id' => $actor?->id,
            'requester_user_id' => $actor?->id,
            'reviewer_user_id' => $reviewerId,
            'authorizer_user_id' => $authorizerId,
            'executor_user_id' => $actor?->id,
            'distributor_id' => $distributorId,
            'branch_id' => $branchId,
            'role_code' => $actor?->role_code,
            'event_type' => $eventType,
            'result' => $result,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'before_state' => $before,
            'after_state' => $after,
            'metadata' => $metadata,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => $correlationId,
            'session_id' => $sessionId,
            'device_id' => $request?->cookie('mv_device'),
            'display_timezone' => (string) config('credit.display_timezone'),
            'occurred_at' => now('UTC'),
        ]);

        $this->accessRecorder->audit($eventType, $result, $actor, $distributorId === null ? null : User::query()->find($distributorId), [
            'branch_id' => $branchId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'metadata' => $metadata,
            'correlation_id' => $correlationId,
        ]);

        return $event;
    }

    /** @param array<string, mixed> $payload */
    public function event(string $type, string $deduplicationKey, array $payload): void
    {
        if (strlen($deduplicationKey) > 150) {
            $deduplicationKey = 'credit:'.hash('sha256', $type.'|'.$deduplicationKey);
        }
        $event = $this->outbox->record($type, $deduplicationKey, $payload);
        if ($event->wasRecentlyCreated) {
            DB::afterCommit(fn () => CreditDomainEvent::dispatch((string) $event->public_id, $type, $payload));
        }
    }
}
