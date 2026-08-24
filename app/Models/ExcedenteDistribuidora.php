<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ExcedenteDistribuidora extends Model
{
    use HasUuids;

    protected $table = 'distributor_surpluses';

    protected $fillable = [
        'distributor_id', 'branch_id', 'origin_relation_id', 'bank_movement_id',
        'original_amount', 'available_amount', 'reserved_amount', 'status',
    ];

    protected $attributes = ['status' => 'PENDING_DECISION', 'reserved_amount' => '0.0000'];

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:4', 'available_amount' => 'decimal:4', 'reserved_amount' => 'decimal:4'];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function originRelation(): BelongsTo
    {
        return $this->belongsTo(RelacionDistribuidora::class, 'origin_relation_id');
    }

    public function bankMovement(): BelongsTo
    {
        return $this->belongsTo(MovimientoBancario::class, 'bank_movement_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(AplicacionExcedente::class, 'surplus_id')->orderBy('applied_at');
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(SolicitudDevolucionExcedente::class, 'surplus_id')->latest();
    }
}
