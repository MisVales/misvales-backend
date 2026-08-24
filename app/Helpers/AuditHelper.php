<?php

namespace App\Helpers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\UserRoleScope;
use Illuminate\Http\Request;

class AuditHelper
{
    public static function log(string $eventName, string $entityType, string $entityId, ?string $actorId, ?string $branchId, ?array $previous = null, ?array $new = null, ?string $reason = null, ?string $result = null, ?int $version = null, ?string $authorizerId = null, ?string $executorId = null, ?array $evidence = null): void
    {
        $result ??= str_contains($eventName, 'DENIED') ? 'DENIED' : 'SUCCESS';
        $request = app()->bound('request') && request() instanceof Request ? request() : null;
        $actorRole = self::actorRole($actorId, $request);

        AuditLog::create([
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'branch_id' => $branchId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_name' => $eventName,
            'version' => $version,
            'previous_value' => $previous,
            'new_value' => $new,
            'reason' => $reason,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $request?->attributes->get('request_id') ?? $request?->header('X-Request-Id'),
            'correlation_id' => $request?->attributes->get('correlation_id') ?? $request?->header('X-Correlation-Id'),
            'trace_id' => $request?->attributes->get('trace_id') ?? $request?->header('X-Trace-Id'),
            'authorizer_id' => $authorizerId,
            'executor_id' => $executorId,
            'evidence' => $evidence,
            'result' => $result,
        ]);
    }

    private static function actorRole(?string $actorId, ?Request $request): ?string
    {
        if ($actorId === null) {
            return null;
        }

        $roles = $request?->attributes->get('audit_actor_roles', []);
        if (is_array($roles) && array_key_exists($actorId, $roles)) {
            return $roles[$actorId];
        }

        $scopeTable = (new UserRoleScope)->getTable();
        $roleTable = (new Role)->getTable();
        $role = UserRoleScope::query()
            ->join($roleTable, "{$roleTable}.id", '=', "{$scopeTable}.role_id")
            ->where("{$scopeTable}.user_id", $actorId)
            ->where("{$scopeTable}.status", 'ACTIVE')
            ->whereNull("{$scopeTable}.revoked_at")
            ->value("{$roleTable}.code");

        if ($request !== null && is_array($roles)) {
            $roles[$actorId] = is_string($role) ? $role : null;
            $request->attributes->set('audit_actor_roles', $roles);
        }

        return is_string($role) ? $role : null;
    }
}
