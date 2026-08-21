<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PointRedemptionRequest extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'point_redemption_requests';

    protected $fillable = [
        'point_account_id',
        'distributor_id',
        'points',
        'point_value_snapshot',
        'total_amount',
        'status',
        'balance_before',
        'balance_after',
        'requested_by',
        'requested_at',
        'authorized_by',
        'authorized_at',
        'rejection_reason',
        'delivered_by',
        'delivered_at',
        'delivery_notes',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'point_value_snapshot' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'requested_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
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

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function entregador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }
}
