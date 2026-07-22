<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\OutboxEvent;
use App\Modules\Access\Infrastructure\Persistence\Models\SecurityEvent;
use Illuminate\Support\Str;

final class AccountSecurityRecorder
{
    /** @param array<string, mixed> $metadata */
    public function audit(string $rule, string $result, ?User $actor, ?User $target, array $metadata = []): SecurityEvent
    {
        return SecurityEvent::query()->create([
            'actor_user_id' => $actor?->id,
            'target_user_id' => $target?->id,
            'rule_code' => $rule,
            'scope' => $target?->branch_id === null ? 'GLOBAL' : 'BRANCH',
            'result' => $result,
            'correlation_id' => (string) Str::uuid(),
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function outbox(string $type, string $key, array $payload): OutboxEvent
    {
        return OutboxEvent::query()->firstOrCreate(
            ['idempotency_key' => $key],
            ['type' => $type, 'payload' => $payload, 'available_at' => now()],
        );
    }
}
