<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Persistence\Models;

use App\Modules\RiskDelinquency\Domain\Enums\FinancialResult;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $risk_alert_id
 * @property string $evaluation_id
 * @property string $relation_id
 * @property int $position
 * @property CarbonImmutable $cut_at
 * @property CarbonImmutable $due_at
 * @property FinancialResult $source_result
 * @property numeric-string $overdue_balance_snapshot
 * @property string $source_version
 */
final class RiskAlertRelation extends Model
{
    use UsesUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_result' => FinancialResult::class,
            'position' => 'integer',
            'overdue_balance_snapshot' => 'decimal:4',
            'cut_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('La evidencia de alerta es inmutable.'));
        self::deleting(fn (): never => throw new LogicException('La evidencia de alerta es inmutable.'));
    }
}
