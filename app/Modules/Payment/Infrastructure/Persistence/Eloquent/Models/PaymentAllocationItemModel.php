<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** Partida inmutable de una aplicación financiera. */
final class PaymentAllocationItemModel extends Model
{
    use UsesUuidPrimaryKey;

    public $timestamps = false;

    protected $table = 'payment_allocation_items';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'late_fee_amount' => 'decimal:4',
            'interest_amount' => 'decimal:4',
            'insurance_amount' => 'decimal:4',
            'loan_commission_amount' => 'decimal:4',
            'capital_amount' => 'decimal:4',
            'pending_before' => 'array',
            'pending_after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
