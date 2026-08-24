<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SolicitudModificacionVale extends Model
{
    use HasUuids;

    protected $table = 'voucher_modification_requests';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['requested_fields' => 'array', 'requested_changes' => 'encrypted:array', 'changes_before' => 'array', 'changes_after' => 'array', 'decided_at' => 'immutable_datetime', 'token_expires_at' => 'immutable_datetime', 'token_used_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function vale(): BelongsTo
    {
        return $this->belongsTo(Vale::class, 'voucher_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'client_id');
    }
}
