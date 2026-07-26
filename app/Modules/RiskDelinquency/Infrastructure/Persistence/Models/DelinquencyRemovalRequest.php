<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Persistence\Models;

use App\Modules\RiskDelinquency\Domain\Enums\RemovalRequestStatus;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $request_number
 * @property int $distributor_id
 * @property int $branch_id
 * @property int $coordinator_id
 * @property string $delinquency_decision_id
 * @property RemovalRequestStatus $status
 * @property numeric-string $overdue_balance_snapshot
 * @property string|null $prepared_reason
 * @property int|null $decided_by
 * @property string|null $decided_role
 * @property string|null $decision_reason
 * @property int|null $reauthentication_id
 * @property CarbonImmutable $prepared_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $invalidated_at
 * @property int $lock_version
 */
final class DelinquencyRemovalRequest extends Model
{
    use UsesUuidPrimaryKey;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RemovalRequestStatus::class,
            'overdue_balance_snapshot' => 'decimal:4',
            'lock_version' => 'integer',
            'prepared_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las solicitudes de retiro no se eliminan.'));
    }
}
