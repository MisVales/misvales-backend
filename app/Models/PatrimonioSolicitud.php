<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatrimonioSolicitud extends Model
{
    use HasUuids;

    protected $table = 'application_assets_liabilities';

    protected $fillable = [
        'application_id', 'entry_type', 'name', 'amount', 'outstanding_balance',
        'monthly_payment', 'is_active', 'details_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'outstanding_balance' => 'decimal:4',
            'monthly_payment' => 'decimal:4',
            'is_active' => 'boolean',
            'details_payload' => 'array',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudDistribuidora::class, 'application_id');
    }
}
