<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ServicioConsultaDistribuidora
{
    public function listar(array $filtros, User $actor): LengthAwarePaginator
    {
        $consulta = Distribuidora::query()->with($this->relacionesResumen());
        $this->restringirAlcance($consulta, $actor);

        $consulta
            ->when($filtros['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $subconsulta) use ($search): void {
                    $subconsulta->where('distributor_number', 'ilike', "%{$search}%")
                        ->orWhereHas('usuario', fn (Builder $usuarios) => $usuarios->where('name', 'ilike', "%{$search}%"));
                });
            })
            ->when($filtros['branch_id'] ?? null, fn (Builder $query, string $id) => $query->where('branch_id', $id))
            ->when($filtros['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filtros['activation_status'] ?? null, fn (Builder $query, string $status) => $query->whereHas('usuario', fn (Builder $usuarios) => $usuarios->where('state', $status)))
            ->when($filtros['coordinator_id'] ?? null, fn (Builder $query, string $id) => $query->whereHas('coordinadorVigente', fn (Builder $asignacion) => $asignacion->where('coordinator_id', $id)))
            ->when($filtros['category_id'] ?? null, fn (Builder $query, string $id) => $query->whereHas('categoriaVigente.versionCategoria', fn (Builder $version) => $version->where('category_id', $id)));

        return $consulta
            ->orderBy($filtros['sort'] ?? 'created_at', $filtros['direction'] ?? 'desc')
            ->paginate($filtros['per_page'] ?? 20)
            ->withQueryString();
    }

    public function restringirAlcance(Builder $consulta, User $actor): void
    {
        $alcances = $actor->roleScopes()->with('role')->where('status', 'ACTIVE')->whereNull('revoked_at')->get();

        if ($alcances->contains(fn ($alcance) => in_array($alcance->role->code, ['general_manager', 'admin'], true) && $alcance->scope_type === 'GLOBAL')) {
            return;
        }

        if ($alcances->contains(fn ($alcance) => $alcance->role->code === 'distributor')) {
            $consulta->where('user_id', $actor->id);

            return;
        }

        $consulta->whereIn('branch_id', $alcances->pluck('branch_id')->filter()->unique());

        if ($alcances->contains(fn ($alcance) => $alcance->role->code === 'coordinator')) {
            $consulta->whereHas('coordinadorVigente', fn (Builder $asignacion) => $asignacion->where('coordinator_id', $actor->id));
        }
    }

    private function relacionesResumen(): array
    {
        return [
            'usuario',
            'sucursal',
            'solicitud.datosPersonales',
            'coordinadorVigente.coordinator',
            'categoriaVigente.versionCategoria',
        ];
    }
}
