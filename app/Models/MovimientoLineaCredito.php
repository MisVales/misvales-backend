<?php

namespace App\Models;

use App\Enums\TipoMovimientoLineaCredito;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoLineaCredito extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'credit_line_movements';

    const UPDATED_AT = null;

    protected $fillable = [
        'credit_line_id',
        'distributor_id',
        'sequence',
        'type',
        'amount',
        'total_authorized_before',
        'total_authorized_after',
        'used_balance_before',
        'used_balance_after',
        'source_type',
        'source_id',
        'reason',
        'performed_by',
        'authorized_by',
        'idempotency_key',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TipoMovimientoLineaCredito::class,
            'amount' => 'decimal:4',
            'total_authorized_before' => 'decimal:4',
            'total_authorized_after' => 'decimal:4',
            'used_balance_before' => 'decimal:4',
            'used_balance_after' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function lineaCredito(): BelongsTo
    {
        return $this->belongsTo(LineaCredito::class, 'credit_line_id');
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function realizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
