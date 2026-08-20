<?php

namespace App\Services\VerificacionDistribuidora;

use App\Exceptions\BusinessException;
use App\Models\DistributorApplication;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ServicioConsultaExpedientes
{
    public function __construct(private readonly ServicioAccesoVerificacion $acceso) {}

    public function listar(string $userId, array $filters): LengthAwarePaginator
    {
        $user = $this->acceso->usuarioActivo($userId);
        $query = DistributorApplication::query()
            ->with($this->relaciones())
            ->where('status', '!=', 'DRAFT');

        $this->aplicarAlcance($query, $user);

        if (! empty($filters['estado'])) {
            $query->where('status', $filters['estado']);
        }

        if (! empty($filters['buscar'])) {
            $term = mb_strtolower($filters['buscar']);
            $query->where(function (Builder $search) use ($term): void {
                $search->whereRaw('LOWER(id::text) LIKE ?', ["%{$term}%"])
                    ->orWhereHas('datosPersonales', function (Builder $personal) use ($term): void {
                        $personal->whereRaw('LOWER(first_name) LIKE ?', ["%{$term}%"])
                            ->orWhereRaw('LOWER(first_last_name) LIKE ?', ["%{$term}%"])
                            ->orWhereRaw('LOWER(COALESCE(second_last_name, \'\')) LIKE ?', ["%{$term}%"]);
                    });
            });
        }

        return $query->latest('created_at')->paginate(min((int) ($filters['por_pagina'] ?? 20), 100));
    }

    public function consultar(string $applicationId, string $userId): DistributorApplication
    {
        $application = DistributorApplication::query()->with($this->relaciones())->find($applicationId);

        if ($application === null || ! $this->acceso->puedeConsultar($application, $userId)) {
            throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
        }

        return $application;
    }

    public function verificadoresDisponibles(string $applicationId, string $coordinatorId): Collection
    {
        $application = DistributorApplication::query()->find($applicationId);

        if ($application === null) {
            throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
        }

        $this->acceso->exigirCoordinador($application, $coordinatorId);

        return User::query()
            ->where('state', 'ACTIVE')
            ->whereNotIn('id', array_filter([$application->submitted_by, $application->coordinator_id]))
            ->whereHas('roleScopes', function ($query) use ($application): void {
                $query->where('status', 'ACTIVE')
                    ->whereNull('revoked_at')
                    ->where('scope_type', 'BRANCH')
                    ->where('branch_id', $application->branch_id)
                    ->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', 'verifier'));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'state']);
    }

    private function aplicarAlcance(Builder $query, User $user): void
    {
        if ($this->acceso->tieneRolGlobal($user, 'general_manager')
            || $this->acceso->tieneRolGlobal($user, 'admin')) {
            return;
        }

        $managerBranches = $user->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('scope_type', 'BRANCH')
            ->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', 'branch_manager'))
            ->pluck('branch_id');

        $query->where(function (Builder $scope) use ($user, $managerBranches): void {
            $scope->where('coordinator_id', $user->id)->orWhere('verifier_id', $user->id);

            if ($managerBranches->isNotEmpty()) {
                $scope->orWhereIn('branch_id', $managerBranches);
            }
        });
    }

    private function relaciones(): array
    {
        return [
            'branch:id,name',
            'datosPersonales:id,application_id,first_name,first_last_name,second_last_name',
            'verificationVisits' => fn ($query) => $query->latest('created_at')->with('mediaFiles'),
            'corrections' => fn ($query) => $query->oldest('corrected_at')->with('visit'),
            'evaluation',
            'authorization',
        ];
    }
}
