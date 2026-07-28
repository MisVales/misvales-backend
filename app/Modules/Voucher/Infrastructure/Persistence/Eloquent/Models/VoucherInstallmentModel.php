<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Parcialidad materializada por M08 y enlazable por M10. */
final class VoucherInstallmentModel extends Model
{
    use UsesUuidPrimaryKey;

    public $timestamps = false;

    protected $table = 'voucher_installments';

    protected $guarded = ['*'];

    /** @return BelongsTo<VoucherModel, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(VoucherModel::class, 'voucher_id');
    }

    protected function casts(): array
    {
        return [
            'payment_number' => 'integer',
            'total_payments' => 'integer',
            'capital_amount' => 'decimal:4',
            'loan_commission_amount' => 'decimal:4',
            'interest_amount' => 'decimal:4',
            'insurance_amount' => 'decimal:4',
            'base_payment_amount' => 'decimal:4',
            'distributor_profit_amount' => 'decimal:4',
            'client_total_amount' => 'decimal:4',
            'misvales_due_amount' => 'decimal:4',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $installment): void {
            if (array_diff(array_keys($installment->getDirty()), ['relation_status', 'relation_item_id']) !== []) {
                throw new LogicException('Los importes de una parcialidad son inmutables.');
            }
        });
        self::deleting(fn (): never => throw new LogicException('Las parcialidades no se eliminan.'));
    }
}
