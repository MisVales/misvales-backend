<?php

namespace App\Modules\Access\Application\Authentication;

use App\Modules\Access\Domain\Authentication\TokenNotActive;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountInvitation;
use Illuminate\Support\Facades\DB;

/** Consume una invitación opaca exactamente una vez bajo bloqueo de fila. */
final class ConsumeInvitation
{
    /** @throws TokenNotActive Si la invitación no existe, venció o ya fue consumida. */
    public function execute(string $plainToken): AccountInvitation
    {
        return DB::transaction(function () use ($plainToken): AccountInvitation {
            $invitation = AccountInvitation::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->state !== TokenState::ACTIVE || $invitation->expires_at->isPast()) {
                throw new TokenNotActive('The invitation is not active.');
            }

            $invitation->forceFill(['state' => TokenState::USED, 'used_at' => now()])->save();

            return $invitation;
        });
    }
}
