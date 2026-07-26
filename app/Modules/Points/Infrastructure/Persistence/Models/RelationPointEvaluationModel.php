<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Persistence\Models;

use App\Modules\Points\Domain\Enums\LiquidationClassification;
use App\Modules\Points\Domain\Enums\RelationPointEvaluationResult;
use App\Modules\Points\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $relation_id
 * @property int $distributor_id
 * @property string $point_account_id
 * @property LiquidationClassification $classification
 * @property string $products_capital_basis
 * @property array<string, string> $configuration_version_ids
 * @property int $balance_before
 * @property int $points_earned
 * @property int $points_penalized
 * @property int $balance_after
 * @property RelationPointEvaluationResult $result
 * @property CarbonImmutable $processed_at
 */
final class RelationPointEvaluationModel extends Model
{
    use UsesUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $table = 'relation_point_evaluations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'classification' => LiquidationClassification::class,
            'result' => RelationPointEvaluationResult::class,
            'configuration_version_ids' => 'array',
            'balance_before' => 'integer',
            'points_earned' => 'integer',
            'points_penalized' => 'integer',
            'balance_after' => 'integer',
            'effective_liquidation_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('La evaluación final de una relación es inmutable.'));
        self::deleting(fn (): never => throw new LogicException('La evaluación final de una relación no se elimina.'));
    }
}
