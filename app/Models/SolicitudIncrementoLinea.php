<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudIncrementoLinea extends Model
{
    use HasUuids;

    protected $table = 'credit_increase_requests';

    protected $fillable = [
        'credit_line_id',
        'distributor_id',
        'requested_amount',
        'recommended_amount',
        'authorized_amount',
        'status',
        'coordinator_id',
        'manager_id',
        'distributor_notes',
        'coordinator_notes',
        'manager_notes',
        'pre_authorized_at',
        'resolved_at',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'recommended_amount' => 'decimal:2',
        'authorized_amount' => 'decimal:2',
        'pre_authorized_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function lineaCredito(): BelongsTo
    {
        return $this->belongsTo(LineaCredito::class, 'credit_line_id');
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function gerente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
