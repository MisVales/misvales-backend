<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property int $user_id
 * @property string $application
 * @property string|null $device_id
 * @property string|null $ip_address
 * @property int $context_version
 * @property Carbon $last_activity_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property string $state
 * @property-read User $user
 */
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<RefreshToken, $this> */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /** @return HasMany<PersonalAccessToken, $this> */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class, 'auth_session_id');
    }
}
