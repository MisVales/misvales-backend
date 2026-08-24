<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TransferenciaBancariaSimulada extends Model
{
    use HasUuids;

    protected $table = 'simulated_bank_transfers';

    protected $fillable = [
        'branch_id',
        'relation_id',
        'created_by',
        'concept',
        'payment_reference',
        'amount',
        'bank_folio',
        'paid_at',
        'payment_type',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function relation(): BelongsTo
    {
        return $this->belongsTo(RelacionDistribuidora::class, 'relation_id');
    }
}
