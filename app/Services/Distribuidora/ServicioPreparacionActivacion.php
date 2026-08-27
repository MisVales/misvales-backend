<?php

namespace App\Services\Distribuidora;

use App\Enums\ApplicationAuthorizationDecision;
use App\Enums\ApplicationStatus;
use App\Enums\BaseStatus;
use App\Enums\VersionStatus;
use App\Models\CategoryVersion;
use App\Models\DatosPersonalesSolicitud;
use App\Models\DistributorApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ServicioPreparacionActivacion
{
    public function solicitudesAutorizadas(User $actor): Collection
    {
        $consulta = DistributorApplication::query()
            ->with(['branch:id,name', 'authorization:id,application_id,decision,authorized_at', 'coordinator:id,name', 'datosPersonales:id,application_id,first_name,first_last_name,second_last_name'])
            ->where('status', ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION)
            ->whereDoesntHave('distribuidora')
            ->whereHas('authorization', fn (Builder $query) => $query->where('decision', ApplicationAuthorizationDecision::APPROVED));

        $this->restringirGerencia($consulta, $actor);

        return $consulta->oldest('created_at')->get()->map(fn (DistributorApplication $solicitud) => [
            'id' => $solicitud->id,
            'applicant_name' => $this->nombre($solicitud->datosPersonales),
            'branch' => ['id' => $solicitud->branch_id, 'name' => $solicitud->branch?->name],
            'coordinator' => ['id' => $solicitud->coordinator_id, 'name' => $solicitud->coordinator?->name],
            'authorization' => [
                'id' => $solicitud->authorization->id,
                'decision' => 'AUTORIZADA',
                'authorized_at' => $solicitud->authorization->authorized_at?->toIso8601String(),
            ],
            'lock_version' => $solicitud->lock_version,
        ]);
    }

    public function categoriasDisponibles(): Collection
    {
        $fecha = now();

        return CategoryVersion::query()
            ->with('category:id,code,status')
            ->where('status', VersionStatus::PUBLISHED)
            ->where('effective_from', '<=', $fecha)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $fecha))
            ->whereHas('category', fn (Builder $query) => $query->where('status', BaseStatus::ACTIVE))
            ->orderBy('name')
            ->get()
            ->map(fn (CategoryVersion $version) => [
                'category_id' => $version->category_id,
                'category_version_id' => $version->id,
                'code' => $version->category->code,
                'name' => $version->name,
                'description' => $version->description,
                'profit_percentage' => $version->profit_percentage,
                'effective_from' => $version->effective_from?->toIso8601String(),
                'effective_to' => $version->effective_to?->toIso8601String(),
            ]);
    }

    private function restringirGerencia(Builder $consulta, User $actor): void
    {
        $global = $actor->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('scope_type', 'GLOBAL')
            ->whereHas('role', fn (Builder $query) => $query->where('code', 'general_manager'))
            ->exists();
        if ($global) {
            return;
        }

        $sucursales = $actor->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('scope_type', 'BRANCH')
            ->whereHas('role', fn (Builder $query) => $query->where('code', 'branch_manager'))
            ->pluck('branch_id');
        $consulta->whereIn('branch_id', $sucursales);
    }

    private function nombre(?DatosPersonalesSolicitud $datos): string
    {
        return trim(implode(' ', array_filter([
            $datos?->first_name,
            $datos?->first_last_name,
            $datos?->second_last_name,
        ])));
    }
}
