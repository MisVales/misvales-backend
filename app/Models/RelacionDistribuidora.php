<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RelacionDistribuidora extends Model
{
    use HasUuids;

    protected $table = 'distributor_relations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cutoff_at' => 'immutable_datetime', 'advance_period_start' => 'immutable_datetime', 'advance_period_end' => 'immutable_datetime', 'payment_deadline_at' => 'immutable_datetime', 'header_snapshot' => 'array', 'bank_snapshot' => 'array', 'portfolio_total' => 'decimal:4', 'misvales_total' => 'decimal:4', 'reconciled_total' => 'decimal:4', 'surcharge_total' => 'decimal:4', 'balance' => 'decimal:4'];
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function partidas(): HasMany
    {
        return $this->hasMany(RelacionPartidaDistribuidora::class, 'relation_id');
    }
}
