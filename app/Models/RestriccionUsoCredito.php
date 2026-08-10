<?php

namespace App\Models;

use App\Enums\EstadoRestriccionUsoCredito;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestriccionUsoCredito extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'credit_usage_restrictions';

    protected $fillable = [
        'credit_line_id',
        'distributor_id',
        'type',
        'status',
        'base_total',
        'tolerance_amount',
        'configuration_version_id',
        'source_type',
        'source_id',
        'reserved_voucher_id',
        'activated_at',
        'reserved_at',
        'consumed_at',
        'cancelled_at',
        'created_by',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'status' => \App\Enums\EstadoRestriccionUsoCredito::class,
            'base_total' => 'decimal:4',
            'tolerance_amount' => 'decimal:4',
            'activated_at' => 'immutable_datetime',
            'reserved_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function lineaCredito(): BelongsTo
    {
        return $this->belongsTo(LineaCredito::class, 'credit_line_id');
    }
}
