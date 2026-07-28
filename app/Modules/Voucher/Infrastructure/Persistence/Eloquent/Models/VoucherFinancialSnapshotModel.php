<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Snapshot financiero inmutable del momento de generación. */
final class VoucherFinancialSnapshotModel extends Model
{
    use UsesUuidPrimaryKey;

    public $timestamps = false;

    protected $table = 'voucher_financial_snapshots';

    protected $guarded = ['*'];

    /** @return BelongsTo<VoucherModel, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(VoucherModel::class, 'voucher_id');
    }

    protected function casts(): array
    {
        return [
            'product_version' => 'integer',
            'capital_amount' => 'decimal:4',
            'loan_commission_rate' => 'decimal:6',
            'loan_commission_amount' => 'decimal:4',
            'fortnightly_interest_rate' => 'decimal:6',
            'total_interest_amount' => 'decimal:4',
            'insurance_amount' => 'decimal:4',
            'fortnights' => 'integer',
            'category_version' => 'integer',
            'distributor_profit_rate' => 'decimal:6',
            'distributor_profit_amount' => 'decimal:4',
            'misvales_total' => 'decimal:4',
            'base_installment_amount' => 'decimal:4',
            'profit_installment_amount' => 'decimal:4',
            'client_installment_amount' => 'decimal:4',
            'client_total' => 'decimal:4',
            'internal_precision' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('El snapshot financiero es inmutable.'));
        self::deleting(fn (): never => throw new LogicException('El snapshot financiero no se elimina.'));
    }
}
