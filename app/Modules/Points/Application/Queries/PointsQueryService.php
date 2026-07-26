<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\Queries;

use App\Models\User;
use App\Modules\Configuration\Application\Contracts\ConfigurationReadContract;
use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use App\Modules\Points\Application\Services\PointAccountService;
use App\Modules\Points\Application\Services\RedemptionPeriodService;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Points\Infrastructure\Persistence\Models\PointAccountModel;
use App\Modules\Points\Infrastructure\Persistence\Models\PointLedgerEntryModel;
use App\Modules\Points\Infrastructure\Persistence\Models\PointRedemptionRequestModel;
use App\Modules\Points\Infrastructure\Persistence\Models\RelationPointEvaluationModel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class PointsQueryService
{
    public function __construct(
        private PointAccountService $accounts,
        private ConfigurationReadContract $configuration,
        private RedemptionPeriodService $periods,
    ) {}

    /** @return array<string, mixed> */
    public function balance(User $distributor): array
    {
        $account = $this->accounts->createForDistributor($distributor->id);
        $pointValue = $this->configuration->resolve(ConfigurationKey::POINT_VALUE_AMOUNT, now('UTC')->toImmutable());
        $period = $this->periods->current(CarbonImmutable::now('America/Monterrey'));
        $equivalent = bcmul((string) $account->available_points, $pointValue->value, 2);

        return [
            'distributor_id' => $distributor->public_id,
            'total_points' => (int) $account->total_points,
            'reserved_points' => (int) $account->reserved_points,
            'available_points' => (int) $account->available_points,
            'current_point_value' => number_format((float) $pointValue->value, 2, '.', ''),
            'available_cash_equivalent' => $equivalent,
            'redemption_open' => $period !== null,
            'redemption_period' => $period === null ? null : [
                'id' => $period->public_id,
                'starts_at' => $period->starts_at->timezone('America/Monterrey')->toIso8601String(),
                'ends_at' => $period->ends_at->timezone('America/Monterrey')->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PointLedgerEntryModel>
     */
    public function movements(User $distributor, array $filters, int $perPage): LengthAwarePaginator
    {
        $account = PointAccountModel::query()->where('distributor_id', $distributor->id)->first();
        if ($account === null) {
            return PointLedgerEntryModel::query()->whereRaw('1 = 0')->paginate($perPage);
        }

        return PointLedgerEntryModel::query()
            ->where('point_account_id', $account->id)
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['relation_id'] ?? null, fn ($query, $relation) => $query->where('relation_id', $relation))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->where('occurred_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->where('occurred_at', '<=', $date))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @return array{evaluation: RelationPointEvaluationModel, movements: list<PointLedgerEntryModel>} */
    public function relation(string $relationId): array
    {
        $evaluation = RelationPointEvaluationModel::query()->where('relation_id', $relationId)->first();
        if ($evaluation === null) {
            throw new PointsDomainException('POINT_ACCOUNT_NOT_FOUND', 'La relación no tiene una evaluación visible.', 404);
        }

        return [
            'evaluation' => $evaluation,
            'movements' => PointLedgerEntryModel::query()
                ->where('relation_id', $relationId)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PointRedemptionRequestModel>
     */
    public function redemptionsForDistributor(User $distributor, array $filters, int $perPage): LengthAwarePaginator
    {
        return PointRedemptionRequestModel::query()
            ->with(['distributor', 'branchSnapshot', 'period'])
            ->where('distributor_id', $distributor->id)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
