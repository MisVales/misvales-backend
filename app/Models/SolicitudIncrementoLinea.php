<?php

namespace App\Models;

use App\Enums\DecisionGerencialIncremento;
use App\Enums\EstadoSolicitudIncremento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudIncrementoLinea extends Model
{
    use HasUuids, HasFactory;

    protected $table = 'credit_increase_requests';

    protected $fillable = [
        'request_number',
        'distributor_id',
        'credit_line_id',
        'branch_id',
        'coordinator_id',
        'status',
        'requested_amount',
        'recommended_amount',
        'authorized_amount',
        'line_total_at_request',
        'used_balance_at_request',
        'available_balance_at_request',
        'request_reason',
        'requested_by',
        'requested_at',
        'coordinator_decided_by',
        'coordinator_decided_at',
        'coordinator_reason',
        'manager_decision',
        'manager_decided_by',
        'manager_decided_at',
        'manager_reason',
        'restriction_id',
        'completed_at',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'status' => EstadoSolicitudIncremento::class,
            'manager_decision' => DecisionGerencialIncremento::class,
            'requested_amount' => 'decimal:4',
            'recommended_amount' => 'decimal:4',
            'authorized_amount' => 'decimal:4',
            'line_total_at_request' => 'decimal:4',
            'used_balance_at_request' => 'decimal:4',
            'available_balance_at_request' => 'decimal:4',
            'requested_at' => 'immutable_datetime',
            'coordinator_decided_at' => 'immutable_datetime',
            'manager_decided_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function lineaCredito(): BelongsTo
    {
        return $this->belongsTo(LineaCredito::class, 'credit_line_id');
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function coordinadorSnapshot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function coordinadorDecisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_decided_by');
    }

    public function gerenteDecisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_decided_by');
    }

    public function restriccion(): BelongsTo
    {
        return $this->belongsTo(RestriccionUsoCredito::class, 'restriction_id');
    }

    public function transiciones()
    {
        return $this->hasMany(TransicionEstadoIncremento::class, 'request_id');
    }
}
