<?php

namespace App\Models;

use App\Enums\EstadoSolicitudIncremento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransicionEstadoIncremento extends Model
{
    use HasUuids;

    protected $table = 'credit_increase_state_transitions';

    const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'actor_id',
        'from_status',
        'to_status',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => EstadoSolicitudIncremento::class,
            'to_status' => EstadoSolicitudIncremento::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudIncrementoLinea::class, 'request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
