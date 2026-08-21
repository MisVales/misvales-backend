<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PointMovement extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'point_movements';

    const UPDATED_AT = null;

    protected $fillable = [
        'point_account_id',
        'distributor_id',
        'type',
        'points',
        'point_value_snapshot',
        'amount',
        'balance_before',
        'balance_after',
        'source_type',
        'source_id',
        'performed_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'point_value_snapshot' => 'decimal:4',
            'amount' => 'decimal:4',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function cuentaPuntos(): BelongsTo
    {
        return $this->belongsTo(PointAccount::class, 'point_account_id');
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function ejecutadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
