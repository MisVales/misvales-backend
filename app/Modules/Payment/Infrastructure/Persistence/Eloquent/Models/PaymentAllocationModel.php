<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Payment\Domain\Enums\PaymentApplicationMode;
use App\Modules\Payment\Domain\Enums\PaymentSourceType;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Registro financiero inmutable; la aplicación no expone mutaciones sobre este modelo. */
final class PaymentAllocationModel extends Model
{
    use UsesUuidPrimaryKey;

    public $timestamps = false;

    protected $table = 'payment_allocations';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return HasMany<PaymentAllocationItemModel, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PaymentAllocationItemModel::class, 'payment_allocation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_type' => PaymentSourceType::class,
            'application_mode' => PaymentApplicationMode::class,
            'received_amount' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'excess_amount' => 'decimal:4',
            'late_fee_amount' => 'decimal:4',
            'interest_amount' => 'decimal:4',
            'insurance_amount' => 'decimal:4',
            'loan_commission_amount' => 'decimal:4',
            'capital_amount' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'effective_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
