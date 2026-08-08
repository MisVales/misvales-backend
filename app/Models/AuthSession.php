<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthSession extends Model
{
    use HasFactory, HasUuids;

    const UPDATED_AT = null; // La migración no tiene updated_at, solo created_at

    protected $fillable = [
        'user_id',
        'session_identifier_hash',
        'authentication_method',
        'mfa_method',
        'mfa_verified_at',
        'ip_address',
        'user_agent',
        'device_name',
        'last_activity_at',
        'expires_at',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
    ];

    protected $casts = [
        'mfa_verified_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuthSession $session): void {
            $session->expires_at ??= now()->addHours(8);
            $session->last_activity_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
