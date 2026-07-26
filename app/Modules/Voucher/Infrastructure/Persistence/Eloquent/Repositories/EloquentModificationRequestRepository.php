<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Repositories;

use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Voucher\Application\Contracts\ModificationRequestRepository;
use App\Modules\Voucher\Application\Security\VoucherActorContext;
use App\Modules\Voucher\Domain\Enums\DataChangeRequestStatus;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\DataChangeRequestModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentModificationRequestRepository implements ModificationRequestRepository
{
    public function hasActiveForVoucher(string $voucherId): bool
    {
        return DataChangeRequestModel::query()
            ->where('voucher_id', $voucherId)
            ->whereIn('status', [
                DataChangeRequestStatus::PENDING->value,
                DataChangeRequestStatus::AUTHORIZED->value,
            ])
            ->exists();
    }

    public function lockScoped(string $id, VoucherActorContext $actor): DataChangeRequestModel
    {
        $query = DataChangeRequestModel::query()->whereKey($id);
        $this->applyScope($query, $actor);

        return $query->lockForUpdate()->first() ?? throw VoucherDomainException::notFound();
    }

    public function findScoped(string $id, VoucherActorContext $actor): DataChangeRequestModel
    {
        $query = DataChangeRequestModel::query()->whereKey($id);
        $this->applyScope($query, $actor);

        return $query->first() ?? throw VoucherDomainException::notFound();
    }

    /** @return LengthAwarePaginator<int, DataChangeRequestModel> */
    public function list(VoucherActorContext $actor, array $filters): LengthAwarePaginator
    {
        $query = DataChangeRequestModel::query();
        $this->applyScope($query, $actor);
        if (is_string($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (is_string($filters['voucher_id'] ?? null)) {
            $query->where('voucher_id', $filters['voucher_id']);
        }

        return $query->latest('requested_at')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
    }

    /** @param Builder<DataChangeRequestModel> $query */
    private function applyScope(Builder $query, VoucherActorContext $actor): void
    {
        if (in_array($actor->role, [RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR], true)) {
            return;
        }
        if ($actor->branchId === null) {
            throw VoucherDomainException::scopeDenied();
        }

        $query->where('branch_id', $actor->branchId);
    }
}
