<?php
namespace App\Helpers;
use App\Models\AuditLog;
use App\Models\User;

class AuditHelper {
    public static function log(string $eventName, string $entityType, string $entityId, ?string $actorId, ?string $branchId, ?array $previous = null, ?array $new = null, ?string $reason = null, ?string $result = null, ?int $version = null) {
        $actorRole = null;
        if ($actorId) {
            $user = User::find($actorId);
            $actorRole = $user ? ($user->roles()->first()?->code) : null;
        }

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
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => request()->header('X-Request-Id') ?? request()->header('X-Correlation-ID') ?? (string) request()->id,
            'result' => $result
        ]);
    }
}
