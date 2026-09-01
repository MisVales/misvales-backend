<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class MovimientoBancario extends Model
{
    use HasUuids;

    protected $table = 'bank_movements';

    protected $fillable = [
        'import_id',
        'process_run_id',
        'row_number',
        'original_row',
        'payment_reference',
        'amount',
        'paid_at',
        'bank_folio',
        'idempotency_bank_folio',
        'duplicate_of_id',
        'concept',
        'classification',
        'reconciliation_status',
        'relation_id',
        'target_voucher_id',
        'distributor_id',
        'balance_before',
        'applied_amount',
        'surplus_amount',
        'reconciled_by',
        'reconciled_at',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'original_row' => 'array',
            'errors' => 'array',
            'amount' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'surplus_amount' => 'decimal:4',
            'paid_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ImportacionArchivoBancario::class, 'import_id');
    }

    public function relation(): BelongsTo
    {
        return $this->belongsTo(RelacionDistribuidora::class, 'relation_id');
    }

    public function targetVoucher(): BelongsTo
    {
        return $this->belongsTo(Vale::class, 'target_voucher_id');
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function manualRequest(): HasOne
    {
        return $this->hasOne(SolicitudConciliacionManual::class, 'bank_movement_id')->latestOfMany();
    }
}
