<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Configuration\Infrastructure\Persistence\Models\RedemptionPeriodModel;
use App\Modules\Points\Domain\Enums\PointRedemptionStatus;
use App\Modules\Points\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $public_folio
 * @property int $distributor_id
 * @property string $point_account_id
 * @property int $redemption_period_id
 * @property int $branch_id_snapshot
 * @property int $requested_points
 * @property int|null $authorized_points
 * @property string|null $point_value_snapshot
 * @property string|null $point_value_version_id
 * @property string|null $cash_amount
 * @property PointRedemptionStatus $status
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_reason
 * @property CarbonImmutable|null $completed_at
 * @property-read User $distributor
 * @property-read Branch $branchSnapshot
 * @property-read RedemptionPeriodModel $period
 */
final class PointRedemptionRequestModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'point_redemption_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PointRedemptionStatus::class,
            'requested_points' => 'integer',
            'authorized_points' => 'integer',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'value_frozen_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branchSnapshot(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id_snapshot');
    }

    /** @return BelongsTo<RedemptionPeriodModel, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(RedemptionPeriodModel::class, 'redemption_period_id');
    }

    protected static function booted(): void
    {
        self::updating(function (self $request): void {
            if ($request->isDirty(['requested_points', 'distributor_id', 'point_account_id', 'redemption_period_id'])) {
                throw new LogicException('La identidad y los puntos solicitados son inmutables.');
            }
            if (! $request->isDirty('status')) {
                return;
            }
            $from = PointRedemptionStatus::from((string) $request->getOriginal('status'));
            $to = $request->status;
            $allowed = ($from === PointRedemptionStatus::PENDING
                    && in_array($to, [PointRedemptionStatus::AUTHORIZED, PointRedemptionStatus::REJECTED], true))
                || ($from === PointRedemptionStatus::AUTHORIZED && $to === PointRedemptionStatus::COMPLETED);
            if (! $allowed) {
                throw new LogicException('La transición de canje no está permitida.');
            }
        });
        self::deleting(fn (): never => throw new LogicException('Las solicitudes de canje no se eliminan.'));
    }
}
