<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\RiskDelinquency\Domain\Enums\DelinquencyStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskProfileStatus;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Resumen materializado reconstruible; no sustituye evaluaciones ni historial.
 *
 * @property string $id
 * @property int $distributor_id
 * @property int $current_branch_id
 * @property int|null $current_coordinator_id
 * @property int $consecutive_breaches
 * @property string|null $last_evaluated_relation_id
 * @property CarbonImmutable|null $last_evaluated_at
 * @property numeric-string $overdue_balance
 * @property CarbonImmutable|null $financially_regularized_at
 * @property DelinquencyStatus $delinquency_status
 * @property bool $blocked_for_new_vouchers
 * @property CarbonImmutable|null $delinquency_applied_at
 * @property RiskProfileStatus $profile_status
 * @property int $lock_version
 * @property-read User $distributor
 * @property-read Branch $branch
 * @property-read User|null $coordinator
 */
final class DistributorRiskProfile extends Model
{
    use UsesUuidPrimaryKey;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'current_branch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_coordinator_id');
    }

    protected function casts(): array
    {
        return [
            'consecutive_breaches' => 'integer',
            'overdue_balance' => 'decimal:4',
            'delinquency_status' => DelinquencyStatus::class,
            'blocked_for_new_vouchers' => 'boolean',
            'profile_status' => RiskProfileStatus::class,
            'lock_version' => 'integer',
            'last_evaluated_at' => 'immutable_datetime',
            'financially_regularized_at' => 'immutable_datetime',
            'delinquency_applied_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Los perfiles de riesgo no se eliminan.'));
    }
}
