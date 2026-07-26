<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Repositories;

use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Voucher\Application\Contracts\VoucherRepository;
use App\Modules\Voucher\Application\Security\VoucherActorContext;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Consulta la proyección pública que M08 entrega a M09. */
final class EloquentVoucherRepository implements VoucherRepository
{
    /** @return LengthAwarePaginator<int, VoucherModel> */
    public function search(VoucherActorContext $actor, array $filters): LengthAwarePaginator
    {
        $query = VoucherModel::query()->select([
            'id',
            'folio',
            'type',
            'status',
            'branch_id',
            'client_name_snapshot',
            'client_name_normalized',
            'capital_amount',
            'generated_at',
            'lock_version',
        ]);
        $this->applyScope($query, $actor);
        if (is_string($filters['folio'] ?? null)) {
            $query->where('folio', $filters['folio']);
        }
        if (is_string($filters['client_name'] ?? null)) {
            $normalized = mb_strtolower(trim($filters['client_name']));
            $query->where('client_name_normalized', 'like', '%'.$normalized.'%');
        }
        if (is_string($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (is_string($filters['generated_from'] ?? null)) {
            $query->where('generated_at', '>=', $filters['generated_from'].' 00:00:00');
        }
        if (is_string($filters['generated_to'] ?? null)) {
            $query->where('generated_at', '<=', $filters['generated_to'].' 23:59:59');
        }

        $sort = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'generated_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';
        $perPage = min(
            (int) config('voucher.search.maximum_page_size', 100),
            max(1, (int) ($filters['per_page'] ?? config('voucher.search.default_page_size', 20))),
        );

        return $query->orderBy($sort, $direction)->paginate($perPage);
    }

    public function findScoped(string $id, VoucherActorContext $actor): VoucherModel
    {
        $query = VoucherModel::query()->whereKey($id);
        $this->applyScope($query, $actor);

        return $query->first() ?? throw VoucherDomainException::notFound();
    }

    public function lockScoped(string $id, VoucherActorContext $actor): VoucherModel
    {
        $query = VoucherModel::query()->whereKey($id);
        $this->applyScope($query, $actor);

        return $query->lockForUpdate()->first() ?? throw VoucherDomainException::notFound();
    }

    /** @param Builder<VoucherModel> $query */
    private function applyScope(Builder $query, VoucherActorContext $actor): void
    {
        if (in_array($actor->role, [RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR], true)) {
            return;
        }
        if ($actor->role === RoleCode::DISTRIBUTOR) {
            $query->where('distributor_user_id', $actor->userId);

            return;
        }
        if ($actor->branchId === null) {
            throw VoucherDomainException::scopeDenied();
        }

        $query->where('branch_id', $actor->branchId);
    }
}
