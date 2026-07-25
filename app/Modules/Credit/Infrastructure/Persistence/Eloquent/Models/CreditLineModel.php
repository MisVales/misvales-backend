<?php

declare(strict_types=1);

namespace App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $distributor_id
 * @property string $total_authorized
 * @property string $used_balance
 * @property string $available_balance
 * @property string $recovered_capital_total
 * @property int|null $last_movement_id
 * @property int $lock_version
 * @property-read User $distributor
 */
final class CreditLineModel extends Model
{
    use HasPublicUuid;

    protected $table = 'credit_lines';

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    /** @return HasMany<CreditLineMovementModel, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CreditLineMovementModel::class, 'credit_line_id');
    }

    /** @return HasMany<CreditUsageRestrictionModel, $this> */
    public function restrictions(): HasMany
    {
        return $this->hasMany(CreditUsageRestrictionModel::class, 'credit_line_id');
    }

    protected function casts(): array
    {
        return [
            'total_authorized' => 'decimal:4',
            'used_balance' => 'decimal:4',
            'available_balance' => 'decimal:4',
            'recovered_capital_total' => 'decimal:4',
            'lock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las líneas de crédito no se eliminan.'));
    }
}
