<?php

namespace App\Services\Conciliacion;

use App\Models\AuditLog;
use App\Models\User;

final class AuditorConciliacion
{
    public function registrar(
        string $evento,
        string $entidad,
        ?string $entidadId,
        User $actor,
        ?string $branchId,
        ?array $anterior = null,
        ?array $nuevo = null,
        ?string $motivo = null,
        ?string $autorizadorId = null,
        ?string $ejecutorId = null,
        string $resultado = 'SUCCESS'
    ): void {
        $rol = $actor->roleScopes()
            ->select('roles.code')
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.status', 'ACTIVE')
            ->whereNull('user_role_scopes.revoked_at')
            ->value('roles.code');

        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'actor_role' => $rol,
            'branch_id' => $branchId,
            'entity_type' => $entidad,
            'entity_id' => $entidadId,
            'event_name' => $evento,
            'previous_value' => $anterior,
            'new_value' => $nuevo,
            'reason' => $motivo,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => request()->attributes->get('request_id') ?? request()->header('X-Request-Id'),
            'trace_id' => request()->attributes->get('trace_id') ?? request()->header('X-Trace-Id'),
            'correlation_id' => request()->header('X-Correlation-ID'),
            'authorizer_id' => $autorizadorId,
            'executor_id' => $ejecutorId,
            'result' => $resultado,
        ]);
    }
}
