<?php

namespace App\Services\SolicitudDistribuidora;

use App\Models\SolicitudDistribuidora;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Services\Audit\SecurityAuditService;

final class AuditorSolicitudDistribuidora
{
    public function __construct(private readonly SecurityAuditService $auditoria) {}

    /** @param array<string, mixed> $anteriores @param array<string, mixed> $nuevos */
    public function registrar(User $actor, SolicitudDistribuidora $solicitud, string $evento, array $anteriores = [], array $nuevos = [], ?string $razon = null, string $resultado = 'SUCCESS'): void
    {
        $request = request();
        $this->auditoria->log($request, [
            'actor_user_id' => $actor->id,
            'branch_id' => $solicitud->branch_id,
            'event_type' => $evento,
            'severity' => $resultado === 'SUCCESS' ? 'INFO' : 'WARNING',
            'outcome' => $resultado,
            'entity_type' => 'distributor_application',
            'entity_id' => $solicitud->id,
            'metadata' => [
                'actor_role' => $this->rolActivo($actor),
                'application_id' => $solicitud->id,
                'application_number' => $solicitud->application_number,
                'action' => $evento,
                'previous_values' => $this->sanitizar($anteriores),
                'new_values' => $this->sanitizar($nuevos),
                'reason' => $razon,
                'result' => $resultado,
            ],
        ]);
    }

    private function rolActivo(User $actor): ?string
    {
        return UserRoleScope::query()
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $actor->id)
            ->where('user_role_scopes.status', 'ACTIVE')
            ->whereNull('user_role_scopes.revoked_at')
            ->orderByRaw("CASE roles.code WHEN 'general_manager' THEN 1 WHEN 'branch_manager' THEN 2 WHEN 'coordinator' THEN 3 ELSE 4 END")
            ->value('roles.code');
    }

    /** @param array<string, mixed> $valores @return array<string, mixed> */
    private function sanitizar(array $valores): array
    {
        return collect($valores)
            ->reject(fn (mixed $valor, string $clave): bool => preg_match('/curp|rfc|official_id|cipher|hmac|file|proof/i', $clave) === 1)
            ->map(fn (mixed $valor) => is_array($valor) ? $this->sanitizar($valor) : $valor)
            ->all();
    }
}
