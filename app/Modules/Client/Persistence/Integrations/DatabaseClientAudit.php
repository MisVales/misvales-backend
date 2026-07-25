<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Integrations;

use App\Modules\Client\Application\Contracts\ClientAuditPort;
use App\Modules\Client\Application\Security\ClientActorContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persistencia local inmutable compatible con la futura ingesta de M18. */
final class DatabaseClientAudit implements ClientAuditPort
{
    public function record(
        string $eventType,
        ?string $clientId,
        ClientActorContext $actor,
        ?string $distributorId,
        ?string $operationId,
        array $changedFields,
        string $result,
        string $requestId,
        ?string $reason = null,
        ?string $protectedPrevious = null,
        ?string $protectedNew = null,
        ?int $requestedBy = null,
        ?int $authorizedBy = null,
    ): void {
        DB::table('client_audits')->insert([
            'id' => (string) Str::uuid(),
            'client_id' => $clientId,
            'event_type' => $eventType,
            'actor_user_id' => $actor->userId,
            'requested_by' => $requestedBy,
            'authorized_by' => $authorizedBy,
            'auth_session_id' => null,
            'actor_role' => $actor->role->value,
            'branch_id' => $actor->branchId,
            'distributor_id' => $distributorId,
            'related_operation_id' => $operationId,
            'changed_fields' => $changedFields === [] ? null : json_encode($changedFields, JSON_THROW_ON_ERROR),
            'protected_previous_value' => $protectedPrevious,
            'protected_new_value' => $protectedNew,
            'reason' => $reason,
            'result' => $result,
            'request_id' => $requestId,
            'ip_hash' => null,
            'device_hash' => null,
            'occurred_at' => now(),
        ]);
    }
}
