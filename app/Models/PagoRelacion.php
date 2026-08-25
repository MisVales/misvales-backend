<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PagoRelacion extends Model
{
    use HasUuids;

    protected $table = 'relation_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'surcharge_applied' => 'decimal:4', 'interest_applied' => 'decimal:4', 'insurance_applied' => 'decimal:4', 'commission_applied' => 'decimal:4', 'capital_applied' => 'decimal:4', 'line_recovered' => 'decimal:4', 'applied_at' => 'immutable_datetime'];
    }

    public function bankMovement(): BelongsTo
    {
        return $this->belongsTo(MovimientoBancario::class, 'bank_movement_id');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionPago::class, 'payment_id');
    }
}
