<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Services;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\ExcessBalance\Application\Security\ExcessScopeService;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use App\Modules\ExcessBalance\Infrastructure\Persistence\Eloquent\Models\ExcessApplicationModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ExcessQueryService
{
    public function __construct(private ExcessScopeService $scope) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ExcessBalanceModel>
     */
    public function balances(User $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->scope->balances(ExcessBalanceModel::query(), $actor);
        $this->applyBalanceFilters($query, $filters);

        return $query->latest('created_at')->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function balance(User $actor, string $id): ExcessBalanceModel
    {
        return $this->scope->balances(ExcessBalanceModel::query(), $actor)
            ->whereKey($id)
            ->first() ?? throw ExcessBalanceException::notFound();
    }

    /**
     * @return LengthAwarePaginator<int, ExcessApplicationModel>
     */
    public function applications(User $actor, string $balanceId, int $perPage = 20): LengthAwarePaginator
    {
        $balance = $this->balance($actor, $balanceId);

        return ExcessApplicationModel::query()
            ->where('excess_balance_id', $balance->id)
            ->latest('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, RefundRequestModel>
     */
    public function refunds(User $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->scope->refunds(RefundRequestModel::query(), $actor)
            ->when($filters['folio'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder
                ->where('request_number', 'ILIKE', '%'.addcslashes((string) $value, '%_\\').'%'))
            ->when($filters['status'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder
                ->where('status', (string) $value))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder
                ->whereDate('requested_at', '>=', (string) $value))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder
                ->whereDate('requested_at', '<=', (string) $value));

        return $query->latest('requested_at')->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function refund(User $actor, string $id): RefundRequestModel
    {
        return $this->scope->refunds(RefundRequestModel::query(), $actor)
            ->whereKey($id)
            ->first() ?? throw ExcessBalanceException::notFound();
    }

    /**
     * @param  Builder<ExcessBalanceModel>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyBalanceFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['folio'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder
                ->where('public_number', 'ILIKE', '%'.addcslashes((string) $value, '%_\\').'%'))
            ->when($filters['distributor_id'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder
                ->whereIn('distributor_id', User::query()->select('id')->where('public_id', (string) $value)))
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder
                ->whereIn('branch_id', Branch::query()
                    ->select('id')->where('public_id', (string) $value)))
            ->when($filters['status'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder
                ->where('status', (string) $value))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder
                ->whereDate('created_at', '>=', (string) $value))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, mixed $value): Builder => $builder
                ->whereDate('created_at', '<=', (string) $value))
            ->when(($filters['has_retained'] ?? null) === true, fn (Builder $builder): Builder => $builder
                ->where('retained_amount', '>', 0))
            ->when(($filters['has_available'] ?? null) === true, fn (Builder $builder): Builder => $builder
                ->where('available_amount', '>', 0))
            ->when(($filters['has_reservation'] ?? null) === true, fn (Builder $builder): Builder => $builder
                ->where('reserved_refund_amount', '>', 0))
            ->when(($filters['refund_pending'] ?? null) === true, fn (Builder $builder): Builder => $builder
                ->where('status', 'DEVOLUCION_PENDIENTE'));
    }
}
