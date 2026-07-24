<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $auth_session_id
 * @property int $user_id
 * @property string $token_hash
 * @property string $family_id
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 * @property-read AuthSession $session
 * @property-read User $user
 */
class RefreshToken extends Model
{
    protected $fillable = [
        'auth_session_id',
        'user_id',
        'token_hash',
        'family_id',
        'expires_at',
        'used_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @return BelongsTo<AuthSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AuthSession::class, 'auth_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
