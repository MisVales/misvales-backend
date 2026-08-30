<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ClientRegistrationDraft extends Model
{
    use HasUuids;

    protected $table = 'client_registration_drafts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'client_id');
    }
}
