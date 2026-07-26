<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Persistence\Models;

use App\Modules\RiskDelinquency\Domain\Enums\FinancialResult;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $risk_sequence_id
 * @property string $evaluation_id
 * @property string $relation_id
 * @property int $position
 * @property numeric-string $overdue_balance_snapshot
 * @property FinancialResult $source_result
 */
final class RiskSequenceRelation extends Model
{
    use UsesUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'overdue_balance_snapshot' => 'decimal:4',
            'source_result' => FinancialResult::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('La evidencia de secuencia es inmutable.'));
        self::deleting(fn (): never => throw new LogicException('La evidencia de secuencia es inmutable.'));
    }
}
