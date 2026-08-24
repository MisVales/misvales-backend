<?php

namespace App\Services\Notificaciones;

use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\RelacionDistribuidora;
use App\Models\SolicitudIncrementoLinea;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Support\Collection;

final class ResolvedorDestinatarios
{
    public function resolver(array $contexto): Collection
    {
        $payload = is_array($contexto['payload'] ?? null) ? $contexto['payload'] : [];
        $userIds = collect();
        foreach (['user_id', 'actor_id', 'created_by', 'requested_by', 'authorized_by', 'executed_by'] as $key) {
            if (! empty($payload[$key])) {
                $userIds->push($payload[$key]);
            }
        }
        if (! empty($contexto['actor_id'])) {
            $userIds->push($contexto['actor_id']);
        }
        [$distributorId, $branchId] = $this->scope($payload, $contexto);
        if ($distributorId) {
            $distributor = Distribuidora::query()->find($distributorId);
            if ($distributor) {
                $userIds->push($distributor->user_id);
                $branchId ??= $distributor->branch_id;
                $coordinator = CoordinatorDistributorAssignment::query()->where('distributor_id', $distributor->id)->where('status', 'ACTIVE')->whereNull('valid_to')->value('coordinator_id');
                if ($coordinator) {
                    $userIds->push($coordinator);
                }
            }
        }
        $roles = User::query()->where('state', 'ACTIVE')->whereHas('roleScopes', function ($query) use ($branchId): void {
            $query->where('status', 'ACTIVE')->whereNull('revoked_at')->where(function ($scope) use ($branchId): void {
                $scope->whereHas('role', fn ($role) => $role->where('code', 'general_manager'));
                if ($branchId) {
                    $scope->orWhere(fn ($branch) => $branch->where('branch_id', $branchId)->whereHas('role', fn ($role) => $role->where('code', 'branch_manager')));
                }
            });
        })->pluck('id');

        if (($contexto['event_type'] ?? null) === 'REFUND_AUTHORIZED' && $branchId) {
            $cashiers = User::query()->where('state', 'ACTIVE')->whereHas('roleScopes', function ($query) use ($branchId): void {
                $query->where('status', 'ACTIVE')->whereNull('revoked_at')->where('branch_id', $branchId)->whereHas('role', fn ($role) => $role->where('code', 'cashier'));
            })->pluck('id');
            $roles = $roles->merge($cashiers);
        }

        return User::query()->where('state', 'ACTIVE')->whereIn('id', $userIds->merge($roles)->filter()->unique())->get();
    }

    private function scope(array $payload, array $contexto): array
    {
        $distributorId = $payload['distributor_id'] ?? $payload['origin_distributor_id'] ?? null;
        $branchId = $payload['branch_id'] ?? $contexto['branch_id'] ?? null;
        if (! $distributorId && ! empty($payload['voucher_id'])) {
            $entity = Vale::query()->find($payload['voucher_id']);
            [$distributorId, $branchId] = [$entity?->distributor_id, $entity?->branch_id];
        }
        if (! $distributorId && ! empty($payload['relation_id'])) {
            $entity = RelacionDistribuidora::query()->find($payload['relation_id']);
            [$distributorId, $branchId] = [$entity?->distributor_id, $entity?->branch_id];
        }
        if (! $distributorId && ! empty($payload['request_id'])) {
            $distributorId = SolicitudIncrementoLinea::query()->find($payload['request_id'])?->distributor_id;
        }

        return [$distributorId, $branchId];
    }
}
