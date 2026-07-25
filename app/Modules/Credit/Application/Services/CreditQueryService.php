<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Services;

use App\Models\User;
use App\Modules\Credit\Domain\Enums\CreditMovementType;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditIncreaseRequestModel;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineMovementModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class CreditQueryService
{
    public function __construct(
        private CreditScopeService $scope,
        private CreditRestrictionService $restrictions,
    ) {}

    /** @return array<string, mixed> */
    public function summary(User $actor, User $distributor): array
    {
        $this->scope->assertCanReadDistributor($actor, $distributor);
        $line = $this->requiredLine($distributor->id);
        $restriction = $this->restrictions->activeForLine($line->id);
        $range = $restriction === null
            ? null
            : $this->restrictions->range($restriction, new Money($line->available_balance));

        return [
            'id' => $line->public_id,
            'distributor_id' => $distributor->public_id,
            'total_authorized' => (new Money($line->total_authorized))->format(),
            'used_balance' => (new Money($line->used_balance))->format(),
            'available_balance' => (new Money($line->available_balance))->format(),
            'recovered_capital_total' => (new Money($line->recovered_capital_total))->format(),
            'lock_version' => (int) $line->lock_version,
            'last_movement_at' => $line->last_movement_id === null
                ? null
                : $line->movements()->whereKey($line->last_movement_id)->value('occurred_at')?->toIso8601String(),
            'restriction' => $restriction === null ? null : [
                'id' => $restriction->public_id,
                'status' => $restriction->status->value,
                'trigger_type' => $restriction->trigger_type->value,
                'reference_amount' => (new Money($restriction->reference_amount))->format(),
                'tolerance_amount' => (new Money($restriction->tolerance_amount))->format(),
                'lower_limit' => $range?->lower->format(),
                'upper_limit' => $range?->upper->format(),
                'has_admissible_amount' => $range?->hasAdmissibleAmount(),
                'bound_voucher_id' => $restriction->bound_voucher_id,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, CreditLineMovementModel>
     */
    public function movements(User $actor, User $distributor, array $filters): LengthAwarePaginator
    {
        $this->scope->assertCanReadDistributor($actor, $distributor);
        $line = $this->requiredLine($distributor->id);
        $query = $line->movements()->latest('occurred_at');
        if (is_string($filters['type'] ?? null)) {
            $query->where('type', CreditMovementType::from($filters['type'])->value);
        }
        if (is_string($filters['from'] ?? null)) {
            $query->where('occurred_at', '>=', $filters['from']);
        }
        if (is_string($filters['to'] ?? null)) {
            $query->where('occurred_at', '<=', $filters['to']);
        }
        if (is_string($filters['source_type'] ?? null)) {
            $query->where('source_type', $filters['source_type']);
        }
        if (is_string($filters['source_id'] ?? null)) {
            $query->where('source_id', $filters['source_id']);
        }

        return $query->paginate($this->pageSize($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, CreditIncreaseRequestModel>
     */
    public function increaseRequests(User $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->scope->scopeIncreaseRequests(
            CreditIncreaseRequestModel::query()->with(['distributor:id,public_id,name', 'restriction'])->latest('requested_at'),
            $actor,
        );
        foreach ([
            'status' => 'status',
            'branch_id' => 'branch_id',
            'coordinator_id' => 'coordinator_id',
            'distributor_id' => 'distributor_id',
        ] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (is_string($filters['from'] ?? null)) {
            $query->where('requested_at', '>=', $filters['from']);
        }
        if (is_string($filters['to'] ?? null)) {
            $query->where('requested_at', '<=', $filters['to']);
        }

        return $query->paginate($this->pageSize($filters));
    }

    public function increaseRequest(User $actor, CreditIncreaseRequestModel $request): CreditIncreaseRequestModel
    {
        $request->loadMissing('distributor');
        $this->scope->assertCanReadDistributor($actor, $request->distributor);

        return $request->loadMissing('restriction');
    }

    private function requiredLine(int $distributorId): CreditLineModel
    {
        $line = CreditLineModel::query()->where('distributor_id', $distributorId)->first();
        if ($line === null) {
            throw new CreditRuleViolation('No existe línea para la distribuidora.', 'CREDIT_LINE_NOT_FOUND', 404);
        }

        return $line;
    }

    /** @param array<string, mixed> $filters */
    private function pageSize(array $filters): int
    {
        return max(1, min(
            (int) config('credit.max_page_size', 100),
            (int) ($filters['per_page'] ?? config('credit.page_size', 25)),
        ));
    }
}
