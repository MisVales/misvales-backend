<?php

declare(strict_types=1);

namespace App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use App\Modules\Credit\Domain\Enums\RestrictionStatus;
use App\Modules\Credit\Domain\Enums\RestrictionTriggerType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $credit_line_id
 * @property RestrictionTriggerType $trigger_type
 * @property string $trigger_id
 * @property string $base_total_authorized
 * @property string $percentage
 * @property string $tolerance_amount
 * @property string $reference_amount
 * @property RestrictionStatus $status
 * @property string|null $bound_voucher_id
 * @property CarbonImmutable|null $bound_at
 * @property string|null $consumed_by_voucher_id
 * @property CarbonImmutable|null $consumed_at
 */
final class CreditUsageRestrictionModel extends Model
{
    use HasPublicUuid;

    protected $table = 'credit_usage_restrictions';

    protected $guarded = [];

    /** @return BelongsTo<CreditLineModel, $this> */
    public function creditLine(): BelongsTo
    {
        return $this->belongsTo(CreditLineModel::class, 'credit_line_id');
    }

    protected function casts(): array
    {
        return [
            'trigger_type' => RestrictionTriggerType::class,
            'status' => RestrictionStatus::class,
            'base_total_authorized' => 'decimal:4',
            'percentage' => 'decimal:4',
            'tolerance_amount' => 'decimal:4',
            'reference_amount' => 'decimal:4',
            'bound_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las restricciones de crédito no se eliminan.'));
    }
}
