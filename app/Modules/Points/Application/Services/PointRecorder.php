<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Auditoría funcional y outbox compartido, siempre dentro de la transacción llamante. */
final class PointRecorder
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function audit(
        string $eventType,
        string $result,
        string $resourceType,
        string $resourceId,
        ?User $actor,
        ?int $distributorId,
        ?int $branchId,
        array $before = [],
        array $after = [],
        array $metadata = [],
        ?string $idempotencyKey = null,
        ?string $reason = null,
    ): void {
        DB::table('point_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'result' => $result,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role_code,
            'distributor_id' => $distributorId,
            'branch_id' => $branchId,
            'before_state' => $before === [] ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => $after === [] ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'correlation_id' => (string) request()->attributes->get('request_id', Str::uuid()),
            'idempotency_key' => $idempotencyKey,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'reason' => $reason,
            'occurred_at' => now('UTC'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function outbox(string $eventName, string $idempotencyKey, array $payload): void
    {
        $eventId = (string) Str::uuid();
        DB::table('outbox_events')->insertOrIgnore([
            'public_id' => $eventId,
            'type' => $eventName,
            'payload' => json_encode([
                'event_id' => $eventId,
                'event_name' => $eventName,
                'event_version' => 1,
                'occurred_at' => now('UTC')->toIso8601String(),
                'correlation_id' => (string) request()->attributes->get('request_id', Str::uuid()),
                ...$payload,
            ], JSON_THROW_ON_ERROR),
            'idempotency_key' => 'points:'.$idempotencyKey,
            'attempts' => 0,
            'state' => 'PENDING',
            'available_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
