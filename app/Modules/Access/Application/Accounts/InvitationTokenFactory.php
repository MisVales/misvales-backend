<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use RuntimeException;

/** Recrea el token opaco desde datos no secretos y la llave de aplicación; nunca se persiste en claro. */
final class InvitationTokenFactory
{
    public function make(string $invitationPublicId, User $user, InvitationPurpose $purpose): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('APP_KEY is required to issue account invitations.');
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? $key : $decoded;
        }

        $message = implode('|', [
            'misvales-account-invitation-v1',
            $invitationPublicId,
            $user->public_id,
            hash('sha256', $user->normalized_email),
            $purpose->value,
            (string) $user->credential_version,
        ]);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $message, $key, true)), '+/', '-_'), '=');
    }
}
