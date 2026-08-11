<?php

namespace App\Services\Credito;

use App\Models\AuditLog;
use App\Models\User;

class AuditorIncrementos
{
    public function registrar(
        string $evento,
        string $entidad,
        ?string $entidadId,
        ?string $requestId,
        ?User $actor,
        ?string $sucursalId,
        array $anteriores = [],
        array $nuevos = [],
        ?string $motivo = null,
        string $resultado = 'SUCCESS',
        ?string $versionConfiguracion = null
    ): void {
        $rol = null;
        
        if ($actor) {
            $rol = $actor->roleScopes()
                ->select('roles.code')
                ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
                ->where('user_role_scopes.status', 'ACTIVE')
                ->whereNull('user_role_scopes.revoked_at')
                ->value('roles.code');
        }

        if ($versionConfiguracion) {
            $nuevos['configuration_version'] = $versionConfiguracion;
        }

        AuditLog::create([
            'actor_id' => $actor ? $actor->id : null,
            'actor_role' => $rol,
            'branch_id' => $sucursalId,
            'entity_type' => $entidad,
            'entity_id' => $entidadId,
            'request_id' => $requestId,
            'event_name' => $evento,
            'previous_value' => $anteriores === [] ? null : $anteriores,
            'new_value' => $nuevos === [] ? null : $nuevos,
            'reason' => $motivo,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'trace_id' => request()->attributes->get('trace_id') ?? request()->header('X-Trace-Id'),
            'result' => $resultado,
        ]);
    }
}
