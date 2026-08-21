<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PointAccount extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'point_accounts';

    protected $fillable = [
        'distributor_id',
        'balance',
        'reserved',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'reserved' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function canjes(): HasMany
    {
        return $this->hasMany(PointRedemptionRequest::class, 'point_account_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(PointMovement::class, 'point_account_id');
    }

    public function puntosDisponibles(): int
    {
        return max(0, $this->balance - $this->reserved);
    }
}
