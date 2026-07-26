<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Persistence\Models;

use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $decision_number
 * @property int $distributor_id
 * @property string $risk_alert_id
 * @property int $branch_id
 * @property string $decision
 * @property int $decided_by
 * @property string $decided_role
 * @property int|null $reauthentication_id
 * @property numeric-string $overdue_balance_snapshot
 * @property string|null $reason
 * @property array<string, mixed> $before_snapshot
 * @property array<string, mixed> $after_snapshot
 * @property CarbonImmutable $decided_at
 */
final class DelinquencyDecision extends Model
{
    use UsesUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'overdue_balance_snapshot' => 'decimal:4',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Las decisiones de morosidad son inmutables.'));
        self::deleting(fn (): never => throw new LogicException('Las decisiones de morosidad son inmutables.'));
    }
}
