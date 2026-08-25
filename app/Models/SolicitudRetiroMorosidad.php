<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SolicitudRetiroMorosidad extends Model
{
    use HasUuids;

    protected $table = 'delinquency_removal_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decididoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function relacionRegularizada(): BelongsTo
    {
        return $this->belongsTo(RelacionDistribuidora::class, 'regularized_relation_id');
    }
}
