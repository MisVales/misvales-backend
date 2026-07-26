<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Persistence\Models;

use App\Modules\RiskDelinquency\Domain\Enums\RiskAlertStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskAlertType;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property string $alert_number
 * @property int $distributor_id
 * @property int $branch_id
 * @property int|null $coordinator_id
 * @property string $risk_sequence_id
 * @property RiskAlertType $alert_type
 * @property int $breach_count
 * @property numeric-string $overdue_balance_snapshot
 * @property RiskAlertStatus $status
 * @property CarbonImmutable $detected_at
 * @property CarbonImmutable|null $resolved_at
 * @property-read Collection<int, RiskAlertRelation> $relations
 */
final class RiskAlert extends Model
{
    use UsesUuidPrimaryKey;

    protected $guarded = [];

    /** @return HasMany<RiskAlertRelation, $this> */
    public function relations(): HasMany
    {
        return $this->hasMany(RiskAlertRelation::class, 'risk_alert_id')->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            'alert_type' => RiskAlertType::class,
            'status' => RiskAlertStatus::class,
            'breach_count' => 'integer',
            'overdue_balance_snapshot' => 'decimal:4',
            'detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las alertas de riesgo no se eliminan.'));
    }
}
