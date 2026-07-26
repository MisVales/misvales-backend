<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $distributor_id
 * @property int $branch_id
 * @property string $public_number
 * @property string $origin_relation_id
 * @property string $bank_movement_id
 * @property string|null $payment_allocation_id
 * @property string $original_amount
 * @property string $retained_amount
 * @property string $available_amount
 * @property string $applied_amount
 * @property string $reserved_refund_amount
 * @property string $refunded_amount
 * @property string $currency
 * @property ExcessBalanceStatus $status
 * @property string|null $decision
 * @property CarbonImmutable|null $effective_paid_at
 * @property CarbonImmutable|null $decided_at
 * @property int $lock_version
 */
final class ExcessBalanceModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'excess_balances';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:4',
            'retained_amount' => 'decimal:4',
            'available_amount' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'reserved_refund_amount' => 'decimal:4',
            'refunded_amount' => 'decimal:4',
            'status' => ExcessBalanceStatus::class,
            'effective_paid_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }
}
