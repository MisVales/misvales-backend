<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountInvitation;
use Illuminate\Support\Str;

final readonly class InvitationIssuer
{
    public function __construct(
        private AccountSecurityRecorder $recorder,
        private InvitationTokenFactory $tokens,
    ) {}

    public function issue(User $user, InvitationPurpose $purpose): AccountInvitation
    {
        if ($user->credential_version === null) {
            $user->refresh();
        }

        AccountInvitation::query()
            ->where('user_id', $user->id)
            ->where('state', TokenState::ACTIVE->value)
            ->update(['state' => TokenState::REVOKED->value, 'revoked_at' => now()]);

        $publicId = (string) Str::uuid();
        $plainToken = $this->tokens->make($publicId, $user, $purpose);
        $invitation = AccountInvitation::query()->create([
            'public_id' => $publicId,
            'user_id' => $user->id,
            'purpose' => $purpose,
            'email_hash' => hash('sha256', $user->normalized_email),
            'credential_version' => $user->credential_version,
            'token_hash' => hash('sha256', $plainToken),
            'state' => TokenState::ACTIVE,
            'issued_at' => now(),
            'expires_at' => now()->addMinutes((int) config('access.tokens.invitation_ttl_minutes')),
        ]);

        unset($plainToken);

        $this->recorder->outbox('ACCOUNT_INVITATION_PENDING', "account-invitation:{$invitation->public_id}", [
            'invitation_id' => $invitation->public_id,
            'user_id' => $user->public_id,
            'email' => $user->normalized_email,
            'purpose' => $purpose->value,
        ]);

        return $invitation;
    }

    public function currentOrIssue(User $user, InvitationPurpose $purpose): AccountInvitation
    {
        $current = AccountInvitation::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose->value)
            ->where('state', TokenState::ACTIVE->value)
            ->where('expires_at', '>', now())
            ->first();

        return $current ?? $this->issue($user, $purpose);
    }
}
