<?php

namespace App\Modules\Access\Application\Authentication;

use App\Modules\Access\Domain\Authentication\TokenNotActive;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Domain\Sessions\SessionState;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshToken;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshTokenFamily;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Rota un refresh token con bloqueo de familia y un único sucesor activo. */
final class RotateRefreshToken
{
    /** @throws TokenNotActive Si el token o su familia ya no están activos. */
    public function execute(string $currentPlainToken, string $newPlainToken, CarbonImmutable $expiresAt): RefreshToken
    {
        return DB::transaction(function () use ($currentPlainToken, $newPlainToken, $expiresAt): RefreshToken {
            $current = RefreshToken::query()
                ->where('token_hash', hash('sha256', $currentPlainToken))
                ->lockForUpdate()
                ->first();

            if ($current === null || $current->state !== TokenState::ACTIVE || $current->expires_at->isPast()) {
                throw new TokenNotActive('The refresh token is not active.');
            }

            $family = RefreshTokenFamily::query()->lockForUpdate()->findOrFail($current->refresh_token_family_id);
            if ($family->state !== SessionState::ACTIVE || $family->absolute_expires_at->isPast()) {
                throw new TokenNotActive('The refresh token family is not active.');
            }

            $current->forceFill(['state' => TokenState::REPLACED, 'used_at' => now(), 'replaced_at' => now()])->save();

            return RefreshToken::query()->create([
                'refresh_token_family_id' => $family->id,
                'auth_session_id' => $current->auth_session_id,
                'token_hash' => hash('sha256', $newPlainToken),
                'state' => TokenState::ACTIVE,
                'issued_at' => now(),
                'expires_at' => $expiresAt->min($family->absolute_expires_at),
            ]);
        });
    }
}
