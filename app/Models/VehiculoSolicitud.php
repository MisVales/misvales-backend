<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehiculoSolicitud extends Model
{
    use HasUuids;

    protected $table = 'application_vehicles';

    protected $fillable = [
        'application_id', 'vehicle_type', 'brand', 'model', 'model_year',
        'ownership_status', 'details_payload',
    ];

    protected function casts(): array
    {
        return ['model_year' => 'integer', 'details_payload' => 'array'];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudDistribuidora::class, 'application_id');
    }
}
