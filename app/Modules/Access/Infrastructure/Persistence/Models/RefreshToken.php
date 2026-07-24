<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\Authentication\TokenState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property TokenState $state
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $revoked_at
 */
#[Hidden(['token_hash'])]
final class RefreshToken extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'refresh_token_family_id',
        'auth_session_id',
        'token_hash',
        'state',
        'issued_at',
        'expires_at',
        'used_at',
        'replaced_at',
        'revoked_at',
        'replaced_by_id',
    ];

    /** @return BelongsTo<AuthSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AuthSession::class, 'auth_session_id');
    }

    /** @return BelongsTo<RefreshTokenFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(RefreshTokenFamily::class, 'refresh_token_family_id');
    }

    protected function casts(): array
    {
        return [
            'state' => TokenState::class,
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'replaced_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
