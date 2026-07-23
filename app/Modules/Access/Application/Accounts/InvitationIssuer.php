<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountInvitation;
use Carbon\CarbonImmutable;

/**
 * Issues hashed account invitations while keeping the readable token out of persistence.
 */
final class InvitationIssuer
{
    public function currentOrIssue(User $user, InvitationPurpose $purpose, int $ttlMinutes): AccountInvitation
    {
        $active = AccountInvitation::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose->value)
            ->where('state', TokenState::ACTIVE->value)
            ->where('expires_at', '>', now())
            ->first();

        if ($active instanceof AccountInvitation) {
            return $active;
        }

        $plainToken = bin2hex(random_bytes(32));
        $now = CarbonImmutable::now();

        return AccountInvitation::query()->create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'state' => TokenState::ACTIVE,
            'token_hash' => hash('sha256', $plainToken),
            'email_hash' => hash('sha256', (string) $user->normalized_email),
            'credential_version' => (int) $user->credential_version,
            'issued_at' => $now,
            'expires_at' => $now->addMinutes($ttlMinutes),
        ]);
    }
}
