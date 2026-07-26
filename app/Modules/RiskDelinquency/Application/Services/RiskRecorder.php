<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Services;

use App\Models\User;
use App\Modules\RiskDelinquency\Application\Contracts\RiskAuditPort;
use App\Modules\RiskDelinquency\Application\Contracts\RiskClock;
use App\Modules\RiskDelinquency\Application\Contracts\RiskOutboxPort;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Auditoría, historial y outbox insertados por la transacción de negocio. */
final class RiskRecorder implements RiskAuditPort, RiskOutboxPort
{
    public function __construct(private readonly RiskClock $clock) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        string $resourceType,
        string $resourceId,
        int $distributorId,
        ?int $branchId,
        ?User $actor = null,
        array $before = [],
        array $after = [],
        array $metadata = [],
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): void {
        $now = $this->clock->nowUtc();
        DB::table('risk_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'event_type' => $event,
            'result' => 'SUCCESS',
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role_code,
            'distributor_id' => $distributorId,
            'branch_id' => $branchId,
            'before_state' => $before === [] ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => $after === [] ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => (string) request()->attributes->get('request_id', Str::uuid()),
            'display_timezone' => 'America/Monterrey',
            'operational_at' => $this->clock->nowOperational(),
            'occurred_at' => $now,
        ]);

        DB::table('risk_transition_history')->insert([
            'id' => (string) Str::uuid(),
            'distributor_id' => $distributorId,
            'transition_type' => $event,
            'previous_state' => is_scalar($before['state'] ?? null) ? (string) $before['state'] : null,
            'new_state' => is_scalar($after['state'] ?? null) ? (string) $after['state'] : null,
            'risk_alert_id' => $resourceType === 'risk_alert' ? $resourceId : null,
            'decision_id' => $resourceType === 'delinquency_decision' ? $resourceId : null,
            'removal_request_id' => $resourceType === 'removal_request' ? $resourceId : null,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role_code,
            'branch_id' => $branchId,
            'reason' => $reason,
            'before_snapshot' => $before === [] ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_snapshot' => $after === [] ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'effective_at' => $now,
            'created_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function outbox(string $event, string $key, string $aggregateId, array $payload): void
    {
        $eventId = (string) Str::uuid();
        DB::table('outbox_events')->insertOrIgnore([
            'public_id' => $eventId,
            'type' => $event,
            'payload' => json_encode([
                'event_id' => $eventId,
                'event_name' => $event,
                'event_version' => 1,
                'aggregate_id' => $aggregateId,
                'occurred_at' => $this->clock->nowUtc()->toIso8601String(),
                ...$payload,
            ], JSON_THROW_ON_ERROR),
            'idempotency_key' => 'risk:'.$key,
            'attempts' => 0,
            'state' => 'PENDING',
            'available_at' => $this->clock->nowUtc(),
            'created_at' => $this->clock->nowUtc(),
            'updated_at' => $this->clock->nowUtc(),
        ]);
    }
}
