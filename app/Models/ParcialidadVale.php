<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ParcialidadVale extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'voucher_installments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'number' => 'integer', 'capital' => 'decimal:4', 'loan_commission' => 'decimal:4',
            'interest' => 'decimal:4', 'insurance' => 'decimal:4', 'distributor_profit' => 'decimal:4',
            'misvales_payment' => 'decimal:4', 'client_payment' => 'decimal:4', 'due_at' => 'immutable_datetime',
        ];
    }

    public function vale(): BelongsTo
    {
        return $this->belongsTo(Vale::class, 'voucher_id');
    }
}
