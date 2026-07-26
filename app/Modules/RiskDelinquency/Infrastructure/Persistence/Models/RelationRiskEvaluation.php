<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Persistence\Models;

use App\Modules\RiskDelinquency\Domain\Enums\FinancialResult;
use App\Modules\RiskDelinquency\Domain\Enums\RelationRiskEvaluationStatus;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Evidencia financiera versionada e inmutable.
 *
 * @property string $id
 * @property string $relation_id
 * @property int $distributor_id
 * @property int $branch_id
 * @property string $cut_id
 * @property CarbonImmutable $cut_at
 * @property CarbonImmutable $due_at
 * @property FinancialResult|null $source_result
 * @property numeric-string $overdue_balance_snapshot
 * @property RelationRiskEvaluationStatus $evaluation_status
 * @property string $source_version
 * @property int|null $sequence_position
 * @property CarbonImmutable $evaluated_at
 * @property string|null $supersedes_id
 * @property string $idempotency_key
 */
final class RelationRiskEvaluation extends Model
{
    use UsesUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_result' => FinancialResult::class,
            'evaluation_status' => RelationRiskEvaluationStatus::class,
            'overdue_balance_snapshot' => 'decimal:4',
            'sequence_position' => 'integer',
            'cut_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'evaluated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Las evaluaciones de riesgo son inmutables.'));
        self::deleting(fn (): never => throw new LogicException('Las evaluaciones de riesgo son inmutables.'));
    }
}
