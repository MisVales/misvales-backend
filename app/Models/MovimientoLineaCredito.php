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

    protected $fillable = [
        'credit_line_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'source_type',
        'source_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => TipoMovimientoLineaCredito::class,
            'amount' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
        ];
    }

    public function lineaCredito(): BelongsTo
    {
        return $this->belongsTo(LineaCredito::class, 'credit_line_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
