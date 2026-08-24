<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AplicacionExcedente extends Model
{
    use HasUuids;

    protected $table = 'surplus_applications';

    public $timestamps = false;

    protected $fillable = [
        'surplus_id', 'relation_id', 'payment_id', 'amount', 'balance_before',
        'balance_after', 'applied_by', 'process', 'idempotency_key', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4', 'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4', 'applied_at' => 'immutable_datetime',
        ];
    }

    public function surplus(): BelongsTo
    {
        return $this->belongsTo(ExcedenteDistribuidora::class, 'surplus_id');
    }

    public function relation(): BelongsTo
    {
        return $this->belongsTo(RelacionDistribuidora::class, 'relation_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PagoRelacion::class, 'payment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
