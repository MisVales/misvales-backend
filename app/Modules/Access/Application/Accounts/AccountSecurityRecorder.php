<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records security audit and outbox entries for account-related access flows.
 */
final class AccountSecurityRecorder
{
    /** @param array<string, mixed> $metadata */
    public function audit(string $rule, string $result, ?User $actor = null, ?User $target = null, array $metadata = []): void
    {
        if (! DB::getSchemaBuilder()->hasTable('security_events')) {
            return;
        }

        DB::table('security_events')->insert([
            'actor_user_id' => $actor?->id,
            'target_user_id' => $target?->id,
            'rule' => $rule,
            'result' => $result,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function outbox(string $type, string $deduplicationKey, array $payload): void
    {
        if (! DB::getSchemaBuilder()->hasTable('outbox_events')) {
            return;
        }

        DB::table('outbox_events')->updateOrInsert(
            ['deduplication_key' => $deduplicationKey],
            [
                'type' => $type,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
