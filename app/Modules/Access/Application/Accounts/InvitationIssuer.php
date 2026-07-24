<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountInvitation;
use Illuminate\Support\Str;

/**
 * Issues one-use invitations while persisting only their hashes.
 */
final readonly class InvitationIssuer
{
    public function __construct(
        private AccountSecurityRecorder $recorder,
        private InvitationTokenFactory $tokens,
    ) {}

    public function issue(User $user, InvitationPurpose $purpose, ?int $ttlMinutes = null): AccountInvitation
    {
        AccountInvitation::query()
            ->where('user_id', $user->id)
            ->where('state', TokenState::ACTIVE->value)
            ->update([
                'state' => TokenState::REVOKED->value,
                'revoked_at' => now(),
            ]);

        $publicId = (string) Str::uuid();
        $plainToken = $this->tokens->make($publicId, $user, $purpose);
        $invitation = AccountInvitation::query()->create([
            'public_id' => $publicId,
            'user_id' => $user->id,
            'purpose' => $purpose,
            'state' => TokenState::ACTIVE,
            'token_hash' => hash('sha256', $plainToken),
            'email_hash' => hash('sha256', (string) $user->normalized_email),
            'credential_version' => (int) $user->credential_version,
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(
                $ttlMinutes ?? (int) config('access.tokens.invitation_ttl_minutes', 1440),
            ),
        ]);

        unset($plainToken);

        $this->recorder->outbox(
            'ACCOUNT_INVITATION_PENDING',
            "account-invitation:{$invitation->public_id}",
            [
                'invitation_id' => $invitation->public_id,
                'user_id' => $user->public_id,
                'recipient' => $user->normalized_email,
                'template' => 'account-invitation',
                'purpose' => $purpose->value,
            ],
        );

        return $invitation;
    }

    public function currentOrIssue(User $user, InvitationPurpose $purpose, ?int $ttlMinutes = null): AccountInvitation
    {
        $active = AccountInvitation::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose->value)
            ->where('state', TokenState::ACTIVE->value)
            ->where('expires_at', '>', now())
            ->first();

        return $active ?? $this->issue($user, $purpose, $ttlMinutes);
    }
}
