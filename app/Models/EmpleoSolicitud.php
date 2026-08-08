<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpleoSolicitud extends Model
{
    use HasUuids;

    protected $table = 'application_employments';

    protected $fillable = [
        'application_id', 'employer_name', 'job_title', 'started_at', 'ended_at',
        'is_current', 'reference_payload', 'details_payload',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_date',
            'ended_at' => 'immutable_date',
            'is_current' => 'boolean',
            'reference_payload' => 'array',
            'details_payload' => 'array',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudDistribuidora::class, 'application_id');
    }
}
