<?php

declare(strict_types=1);

namespace App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use App\Modules\Credit\Domain\Enums\IncreaseOriginType;
use App\Modules\Credit\Domain\Enums\IncreaseRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property string $folio
 * @property int $distributor_id
 * @property int $credit_line_id
 * @property int $branch_id
 * @property int $coordinator_id
 * @property string $requested_amount
 * @property string|null $recommended_amount
 * @property string|null $authorized_amount
 * @property IncreaseOriginType $origin_type
 * @property string|null $product_amount
 * @property string $available_balance_snapshot
 * @property string|null $required_difference
 * @property string $total_authorized_snapshot
 * @property string $used_balance_snapshot
 * @property int $credit_line_version_snapshot
 * @property IncreaseRequestStatus $status
 * @property string $request_reason
 * @property string|null $coordinator_reason
 * @property string|null $manager_reason
 * @property int $requested_by_user_id
 * @property int|null $reviewed_by_user_id
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $decided_at
 * @property int|null $restriction_id
 * @property int $lock_version
 * @property string $idempotency_key
 * @property-read User $distributor
 * @property-read CreditUsageRestrictionModel|null $restriction
 */
final class CreditIncreaseRequestModel extends Model
{
    use HasPublicUuid;

    protected $table = 'credit_increase_requests';

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    /** @return BelongsTo<CreditLineModel, $this> */
    public function creditLine(): BelongsTo
    {
        return $this->belongsTo(CreditLineModel::class, 'credit_line_id');
    }

    /** @return BelongsTo<CreditUsageRestrictionModel, $this> */
    public function restriction(): BelongsTo
    {
        return $this->belongsTo(CreditUsageRestrictionModel::class, 'restriction_id');
    }

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:4',
            'recommended_amount' => 'decimal:4',
            'authorized_amount' => 'decimal:4',
            'product_amount' => 'decimal:4',
            'available_balance_snapshot' => 'decimal:4',
            'required_difference' => 'decimal:4',
            'total_authorized_snapshot' => 'decimal:4',
            'used_balance_snapshot' => 'decimal:4',
            'origin_type' => IncreaseOriginType::class,
            'status' => IncreaseRequestStatus::class,
            'credit_line_version_snapshot' => 'integer',
            'lock_version' => 'integer',
            'requested_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las solicitudes de incremento no se eliminan.'));
    }
}
