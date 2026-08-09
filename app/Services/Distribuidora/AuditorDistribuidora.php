<?php

namespace App\Services\Distribuidora;

use App\Models\AuditLog;
use App\Models\User;

class AuditorDistribuidora
{
    public function registrar(
        string $evento,
        string $entidad,
        ?string $entidadId,
        User $actor,
        ?string $sucursalId,
        array $anteriores = [],
        array $nuevos = [],
        ?string $motivo = null,
        string $resultado = 'SUCCESS',
    ): void {
        $rol = $actor->roleScopes()
            ->select('roles.code')
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.status', 'ACTIVE')
            ->whereNull('user_role_scopes.revoked_at')
            ->value('roles.code');

        AuditLog::create([
            'actor_id' => $actor->id,
            'actor_role' => $rol,
            'branch_id' => $sucursalId,
            'entity_type' => $entidad,
            'entity_id' => $entidadId,
            'event_name' => $evento,
            'previous_value' => $anteriores === [] ? null : $anteriores,
            'new_value' => $nuevos === [] ? null : $nuevos,
            'reason' => $motivo,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => request()->attributes->get('request_id')
                ?? request()->header('X-Request-Id')
                ?? request()->header('X-Correlation-ID'),
            'trace_id' => request()->attributes->get('trace_id')
                ?? request()->header('X-Trace-Id'),
            'result' => $resultado,
        ]);
    }
}
