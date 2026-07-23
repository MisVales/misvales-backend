<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

class AuthSession extends Model
{
    protected $fillable = [
        'user_id',
        'application',
        'device_id',
        'ip_address',
        'context_version',
        'last_activity_at',
        'expires_at',
        'revoked_at',
        'state',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class, 'auth_session_id');
    }
}
