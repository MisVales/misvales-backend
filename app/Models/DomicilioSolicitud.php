<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomicilioSolicitud extends Model
{
    use HasUuids;

    protected $table = 'application_residences';

    protected $fillable = [
        'application_id', 'is_current', 'street', 'exterior_number', 'interior_number',
        'neighborhood', 'postal_code', 'municipality', 'city', 'state', 'country',
        'housing_tenure', 'financing_status', 'width_meters', 'length_meters',
        'built_area_square_meters', 'details_payload',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'width_meters' => 'decimal:2',
            'length_meters' => 'decimal:2',
            'built_area_square_meters' => 'decimal:2',
            'details_payload' => 'array',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudDistribuidora::class, 'application_id');
    }
}
