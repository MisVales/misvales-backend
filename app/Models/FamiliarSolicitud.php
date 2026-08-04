<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamiliarSolicitud extends Model
{
    use HasUuids;

    protected $table = 'application_family_members';

    protected $fillable = [
        'application_id', 'relationship', 'first_name', 'first_last_name',
        'second_last_name', 'birth_date', 'declared_age', 'school_name',
        'is_family_reference', 'details_payload',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'declared_age' => 'integer',
            'is_family_reference' => 'boolean',
            'details_payload' => 'array',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudDistribuidora::class, 'application_id');
    }
}
