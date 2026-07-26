<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Application\Queries;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Mobility\Application\Security\MobilityAccessService;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;
use App\Modules\Mobility\Infrastructure\Persistence\Models\AdministrativeReassignment;
use App\Modules\Mobility\Infrastructure\Persistence\Models\ClientTransfer;
use App\Modules\Mobility\Infrastructure\Persistence\Models\CoordinatorReassignmentBatch;
use App\Modules\Mobility\Infrastructure\Persistence\Models\DistributorBranchChange;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class MobilityQueryService
{
    public function __construct(private MobilityAccessService $access) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ClientTransfer>
     */
    public function transfers(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->applyFilters(
            $this->access->scopeTransfers(ClientTransfer::query(), $actor),
            $filters,
            ['status', 'client_id', 'origin_distributor_id', 'recipient_distributor_id', 'origin_branch_id', 'origin_coordinator_id'],
            'requested_at',
            'transfer_number',
        );
    }

    public function transfer(User $actor, string $id): ClientTransfer
    {
        $transfer = ClientTransfer::query()->whereKey($id)->firstOrFail();
        $this->access->assertTransferVisible($actor, $transfer);

        return $transfer;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AdministrativeReassignment>
     */
    public function reassignments(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->applyFilters(
            $this->scopeManager(AdministrativeReassignment::query()->with('items'), $actor, 'scope_branch_id'),
            $filters,
            ['status', 'scope_branch_id'],
            'created_at',
            'reassignment_number',
        );
    }

    public function reassignment(User $actor, string $id): AdministrativeReassignment
    {
        $model = $this->scopeManager(AdministrativeReassignment::query()->with('items'), $actor, 'scope_branch_id')
            ->whereKey($id)->first();
        if ($model === null) {
            throw MobilityException::scopeDenied();
        }

        return $model;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DistributorBranchChange>
     */
    public function branchChanges(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->applyFilters(
            $this->scopeManager(DistributorBranchChange::query()->with('clientItems'), $actor, 'origin_branch_id'),
            $filters,
            ['status', 'distributor_id', 'origin_branch_id', 'destination_branch_id', 'destination_coordinator_id'],
            'created_at',
            'change_number',
        );
    }

    public function branchChange(User $actor, string $id): DistributorBranchChange
    {
        $model = $this->scopeManager(DistributorBranchChange::query()->with('clientItems'), $actor, 'origin_branch_id')
            ->whereKey($id)->first();
        if ($model === null) {
            throw MobilityException::scopeDenied();
        }

        return $model;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, CoordinatorReassignmentBatch>
     */
    public function coordinatorBatches(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->applyFilters(
            $this->scopeManager(CoordinatorReassignmentBatch::query()->with('items'), $actor, 'branch_id'),
            $filters,
            ['status', 'branch_id', 'outgoing_coordinator_id'],
            'created_at',
            'batch_number',
        );
    }

    public function coordinatorBatch(User $actor, string $id): CoordinatorReassignmentBatch
    {
        $model = $this->scopeManager(CoordinatorReassignmentBatch::query()->with('items'), $actor, 'branch_id')
            ->whereKey($id)->first();
        if ($model === null) {
            throw MobilityException::scopeDenied();
        }

        return $model;
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function scopeManager(Builder $query, User $actor, string $branchColumn): Builder
    {
        return match ($actor->role_code) {
            RoleCode::GENERAL_MANAGER->value,
            RoleCode::ADMINISTRATOR->value => $query,
            RoleCode::SUCURSAL_MANAGER->value => $query->where($branchColumn, $actor->branch_id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $allowed
     * @return LengthAwarePaginator<int, T>
     */
    private function applyFilters(
        Builder $query,
        array $filters,
        array $allowed,
        string $dateColumn,
        string $folioColumn,
    ): LengthAwarePaginator {
        foreach ($allowed as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['folio'])) {
            $query->where($folioColumn, $filters['folio']);
        }
        $query->when($filters['date_from'] ?? null, fn ($q, $value) => $q->where($dateColumn, '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($q, $value) => $q->where($dateColumn, '<=', $value));

        return $query->orderByDesc($dateColumn)->paginate((int) ($filters['per_page'] ?? 25));
    }
}
