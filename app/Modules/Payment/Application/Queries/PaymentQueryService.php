<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Queries;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Payment\Application\Contracts\RelationPaymentPort;
use App\Modules\Payment\Application\Security\PaymentActorContext;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\BankImportModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\BankMovementModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ClarificationModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ManualReconciliationModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\PaymentAllocationModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Consultas paginadas que aplican alcance antes de materializar filas. */
final readonly class PaymentQueryService
{
    public function __construct(private RelationPaymentPort $relations) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, BankImportModel>
     */
    public function bankImports(PaymentActorContext $actor, array $filters): LengthAwarePaginator
    {
        $query = BankImportModel::query();
        $this->scopeOperational($query, $actor);
        $this->applyCommonFilters($query, $filters, ['status', 'business_date', 'branch_id']);

        return $query->latest('created_at')->paginate($this->perPage($filters));
    }

    public function bankImport(string $id, PaymentActorContext $actor): BankImportModel
    {
        $query = BankImportModel::query()->whereKey($id);
        $this->scopeOperational($query, $actor);

        return $query->first() ?? throw PaymentDomainException::notFound();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, BankMovementModel>
     */
    public function bankMovements(PaymentActorContext $actor, array $filters, ?string $importId = null): LengthAwarePaginator
    {
        $query = BankMovementModel::query()->with('allocation');
        $this->scopeOperational($query, $actor);
        if ($importId !== null) {
            $query->where('bank_import_id', $importId);
        }
        $this->applyCommonFilters($query, $filters, ['status', 'branch_id', 'bank_import_id', 'paid_at']);
        foreach (['bank_folio_normalized', 'payment_reference_normalized'] as $column) {
            if (isset($filters[$column]) && is_string($filters[$column]) && $filters[$column] !== '') {
                $query->where($column, $filters[$column]);
            }
        }

        return $query->orderByDesc('paid_at')->orderByDesc('created_at')->paginate($this->perPage($filters));
    }

    public function bankMovement(string $id, PaymentActorContext $actor): BankMovementModel
    {
        $query = BankMovementModel::query()->with('allocation.items')->whereKey($id);
        $this->scopeOperational($query, $actor);

        return $query->first() ?? throw PaymentDomainException::notFound();
    }

    public function allocation(string $id, PaymentActorContext $actor): PaymentAllocationModel
    {
        $allocation = PaymentAllocationModel::query()->with('items')->find($id);
        if ($allocation === null) {
            throw PaymentDomainException::notFound();
        }
        if ($actor->can(PermissionCode::PAYMENTS_VIEW_GLOBAL->value)) {
            return $allocation;
        }
        if ($allocation->bank_movement_id !== null) {
            $movement = BankMovementModel::query()->find($allocation->bank_movement_id);
            if (
                $movement !== null
                && $actor->can(PermissionCode::PAYMENTS_VIEW_BRANCH->value)
                && $actor->branchId === (int) $movement->branch_id
            ) {
                return $allocation;
            }
        }

        throw PaymentDomainException::notFound();
    }

    /** @return LengthAwarePaginator<int, PaymentAllocationModel> */
    public function relationPayments(string $relationId, PaymentActorContext $actor, int $perPage): LengthAwarePaginator
    {
        if ($actor->can(PermissionCode::PAYMENTS_VIEW_GLOBAL->value)) {
            return PaymentAllocationModel::query()
                ->with('items')
                ->where('relation_id', $relationId)
                ->latest('effective_at')
                ->paginate($perPage);
        }

        // La autorización de propiedad/sucursal debe provenir de M10, nunca del identificador enviado.
        $branchId = $actor->branchId ?? throw PaymentDomainException::relationContractUnavailable();
        $distributorId = $actor->role->value === 'DISTRIBUTOR' ? $actor->userId : 0;
        $this->relations->lockById($relationId, $branchId, $distributorId);

        return PaymentAllocationModel::query()
            ->with('items')
            ->where('relation_id', $relationId)
            ->latest('effective_at')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ClarificationModel>
     */
    public function clarifications(PaymentActorContext $actor, array $filters): LengthAwarePaginator
    {
        $query = ClarificationModel::query();
        $this->scopeDistributorResource($query, $actor);
        $this->applyCommonFilters($query, $filters, ['status', 'branch_id', 'distributor_id', 'relation_id']);

        return $query->latest('created_at')->paginate($this->perPage($filters));
    }

    public function clarification(string $id, PaymentActorContext $actor): ClarificationModel
    {
        $query = ClarificationModel::query()->whereKey($id);
        $this->scopeDistributorResource($query, $actor);

        return $query->first() ?? throw PaymentDomainException::notFound();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ManualReconciliationModel>
     */
    public function manualReconciliations(PaymentActorContext $actor, array $filters): LengthAwarePaginator
    {
        $query = ManualReconciliationModel::query();
        $this->scopeDistributorResource($query, $actor);
        $this->applyCommonFilters($query, $filters, ['status', 'branch_id', 'distributor_id', 'relation_id']);

        return $query->latest('requested_at')->paginate($this->perPage($filters));
    }

    public function manualReconciliation(string $id, PaymentActorContext $actor): ManualReconciliationModel
    {
        $query = ManualReconciliationModel::query()->whereKey($id);
        $this->scopeDistributorResource($query, $actor);

        return $query->first() ?? throw PaymentDomainException::notFound();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ExcessBalanceModel>
     */
    public function excessBalances(PaymentActorContext $actor, array $filters): LengthAwarePaginator
    {
        $query = ExcessBalanceModel::query();
        $this->scopeDistributorResource($query, $actor);
        $this->applyCommonFilters($query, $filters, ['status', 'branch_id', 'distributor_id']);

        return $query->latest('created_at')->paginate($this->perPage($filters));
    }

    public function excessBalance(string $id, PaymentActorContext $actor): ExcessBalanceModel
    {
        $query = ExcessBalanceModel::query()->whereKey($id);
        $this->scopeDistributorResource($query, $actor);

        return $query->first() ?? throw PaymentDomainException::notFound();
    }

    public function refundRequest(string $id, PaymentActorContext $actor): RefundRequestModel
    {
        $query = RefundRequestModel::query()->whereKey($id);
        if ($actor->can(PermissionCode::PAYMENTS_VIEW_GLOBAL->value)) {
            return $query->first() ?? throw PaymentDomainException::notFound();
        }
        if ($actor->can(PermissionCode::PAYMENTS_VIEW_BRANCH->value) && $actor->branchId !== null) {
            $query->where('branch_id', $actor->branchId);
        } elseif ($actor->can(PermissionCode::PAYMENTS_VIEW_OWN->value)) {
            $query->where('distributor_id', $actor->userId);
        } else {
            throw PaymentDomainException::authorizationDenied();
        }

        return $query->first() ?? throw PaymentDomainException::notFound();
    }

    /** @param Builder<BankImportModel>|Builder<BankMovementModel> $query */
    private function scopeOperational(Builder $query, PaymentActorContext $actor): void
    {
        if ($actor->can(PermissionCode::PAYMENTS_VIEW_GLOBAL->value)) {
            return;
        }
        if ($actor->can(PermissionCode::PAYMENTS_VIEW_BRANCH->value) && $actor->branchId !== null) {
            $query->where('branch_id', $actor->branchId);

            return;
        }

        throw PaymentDomainException::authorizationDenied();
    }

    /**
     * @param  Builder<ClarificationModel>|Builder<ManualReconciliationModel>|Builder<ExcessBalanceModel>  $query
     */
    private function scopeDistributorResource(Builder $query, PaymentActorContext $actor): void
    {
        if ($actor->can(PermissionCode::PAYMENTS_VIEW_GLOBAL->value)) {
            return;
        }
        if ($actor->can(PermissionCode::PAYMENTS_VIEW_BRANCH->value) && $actor->branchId !== null) {
            $query->where('branch_id', $actor->branchId);

            return;
        }
        if ($actor->can(PermissionCode::PAYMENTS_VIEW_ASSIGNED->value)) {
            $assignedIds = User::query()
                ->where('coordinator_id', $actor->userId)
                ->select('id');
            $query->whereIn('distributor_id', $assignedIds);

            return;
        }
        if ($actor->can(PermissionCode::PAYMENTS_VIEW_OWN->value)) {
            $query->where('distributor_id', $actor->userId);

            return;
        }

        throw PaymentDomainException::authorizationDenied();
    }

    /**
     * @param  Builder<*>  $query
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $allowed
     */
    private function applyCommonFilters(Builder $query, array $filters, array $allowed): void
    {
        foreach ($allowed as $column) {
            $value = $filters[$column] ?? null;
            if (is_string($value) && $value !== '' || is_int($value)) {
                $query->where($column, $value);
            }
        }
    }

    /** @param array<string, mixed> $filters */
    private function perPage(array $filters): int
    {
        $candidate = $filters['per_page'] ?? 20;

        return is_numeric($candidate) ? max(1, min(100, (int) $candidate)) : 20;
    }
}
