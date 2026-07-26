<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Mobility\Application\Contracts\MobilityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseMobilityRecorder implements MobilityRecorder
{
    public function audit(
        string $event,
        string $aggregateType,
        string $aggregateId,
        User $actor,
        ?int $branchId,
        string $result,
        ?string $reason,
        array $before = [],
        array $after = [],
    ): void {
        DB::table('mobility_audits')->insert([
            'id' => (string) Str::uuid(),
            'event_type' => $event,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->role_code,
            'branch_id' => $branchId,
            'result' => $result,
            'reason' => $reason,
            'before_snapshot' => $before === [] ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_snapshot' => $after === [] ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip_hash' => request()->ip() === null ? null : hash('sha256', request()->ip()),
            'device_hash' => request()->userAgent() === null ? null : hash('sha256', request()->userAgent()),
            'occurred_at' => now(),
        ]);
    }

    public function outbox(
        string $event,
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        ?string $causationId,
        array $payload,
    ): void {
        $eventId = (string) Str::uuid();
        DB::table('outbox_events')->insertOrIgnore([
            'public_id' => $eventId,
            'event_uuid' => $eventId,
            'type' => $event,
            'payload' => json_encode([
                'version' => 1,
                'aggregate' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'correlation_id' => $correlationId,
                'causation_id' => $causationId,
                ...$payload,
            ], JSON_THROW_ON_ERROR),
            'idempotency_key' => "mobility:{$event}:{$aggregateId}",
            'attempts' => 0,
            'state' => 'PENDING',
            'available_at' => now(),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function history(
        string $aggregateType,
        string $aggregateId,
        ?string $previousState,
        string $newState,
        User $actor,
        ?int $branchId,
        string $useCase,
        ?string $reason,
        string $correlationId,
    ): void {
        DB::table('mobility_state_history')->insert([
            'id' => (string) Str::uuid(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'previous_state' => $previousState,
            'new_state' => $newState,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->role_code,
            'branch_id' => $branchId,
            'use_case' => $useCase,
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'snapshot' => null,
            'occurred_at' => now(),
        ]);
    }
}
