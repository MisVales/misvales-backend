<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditoComercialSolicitud extends Model
{
    use HasUuids;

    protected $table = 'application_commercial_credits';

    protected $fillable = [
        'application_id', 'company_name', 'credit_limit', 'is_current',
        'proof_reference', 'details_payload',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'is_current' => 'boolean',
            'details_payload' => 'array',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudDistribuidora::class, 'application_id');
    }
}
