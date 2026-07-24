<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\Sessions\SessionState;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property SessionState $state
 * @property CarbonImmutable $absolute_expires_at
 */
class RefreshTokenFamily extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['auth_session_id', 'application', 'state', 'absolute_expires_at'];

    protected function casts(): array
    {
        return ['state' => SessionState::class, 'absolute_expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** @return HasMany<RefreshToken, $this> */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }
}
