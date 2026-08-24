<?php

namespace App\Services\Alcance;

use App\Enums\TipoAlcanceOperativo;
use App\Models\User;

final class ResolverAlcanceOperativo
{
    public function resolver(User $actor): AlcanceOperativo
    {
        $asignaciones = $actor->roleScopes()
            ->with('role:id,code')
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->get();

        $global = $asignaciones->contains(
            fn ($asignacion): bool => $asignacion->scope_type === 'GLOBAL'
                && $asignacion->role?->code === 'general_manager'
        );
        if ($global) {
            return new AlcanceOperativo(TipoAlcanceOperativo::GLOBAL, $actor->id);
        }

        $sucursales = $asignaciones
            ->filter(fn ($asignacion): bool => $asignacion->scope_type === 'BRANCH'
                && $asignacion->role?->code === 'branch_manager'
                && $asignacion->branch_id !== null)
            ->pluck('branch_id')
            ->unique()
            ->values()
            ->all();
        if ($sucursales !== []) {
            return new AlcanceOperativo(TipoAlcanceOperativo::SUCURSAL, $actor->id, $sucursales);
        }

        return new AlcanceOperativo(TipoAlcanceOperativo::PERSONAL, $actor->id);
    }
}
