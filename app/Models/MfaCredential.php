<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaCredential extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'type',
        'label',
        'confirmed_at',
        'last_used_at',
        'revoked_at',
        'secret_ciphertext',
        'algorithm',
        'digits',
        'period',
        'credential_identifier',
        'public_key',
        'sign_count',
        'transports',
        'aaguid',
        'attestation_format',
        'rp_id',
        'backup_eligible',
        'backup_state',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'transports' => 'array',
        'backup_eligible' => 'boolean',
        'backup_state' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
