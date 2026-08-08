<?php

namespace App\Services\Cliente;

use App\Models\AuditLog;
use App\Models\User;

final class AuditorCliente
{
    public function registrar(
        string $evento,
        ?string $clienteId,
        User $actor,
        ?string $sucursalId,
        ?string $distribuidoraId,
        string $resultado = 'SUCCESS',
        ?string $motivo = null,
        array $nuevos = [],
        array $anteriores = [],
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
            'entity_type' => 'Client',
            'entity_id' => $clienteId,
            'event_name' => $evento,
            'previous_value' => $anteriores === [] ? null : $this->sanitizar($anteriores),
            'new_value' => array_merge(['distributor_id' => $distribuidoraId], $this->sanitizar($nuevos)),
            'reason' => $motivo,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => request()->attributes->get('request_id') ?? request()->header('X-Request-Id'),
            'trace_id' => request()->attributes->get('trace_id') ?? request()->header('X-Trace-Id'),
            'result' => $resultado,
        ]);
    }

    private function sanitizar(array $valores): array
    {
        return collect($valores)
            ->reject(fn (mixed $valor, string $clave): bool => preg_match('/curp|rfc|official|account|clabe|cipher|hmac|address/i', $clave) === 1)
            ->all();
    }
}
