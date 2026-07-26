<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Integrations;

use App\Modules\Payment\Application\Contracts\PaymentAuditPort;
use App\Modules\Payment\Application\Security\PaymentActorContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persistencia inmutable local preparada para la futura ingesta de M18. */
final class DatabasePaymentAudit implements PaymentAuditPort
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        string $result,
        ?PaymentActorContext $actor,
        ?string $resourceType,
        ?string $resourceId,
        array $before,
        array $after,
        array $metadata,
        string $requestId,
        ?string $reason = null,
    ): void {
        DB::table('payment_audits')->insert([
            'id' => (string) Str::uuid(),
            'event_type' => $event,
            'result' => $result,
            'actor_user_id' => $actor?->userId,
            'actor_role' => $actor?->role->value,
            'branch_id' => $actor?->branchId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'reason' => $reason,
            'before_state' => $before === [] ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => $after === [] ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'request_id' => $requestId,
            'occurred_at' => now('UTC'),
        ]);
    }
}
