<?php

namespace App\Modules\Access\Application\MFA;

use App\Models\User;
use SensitiveParameter;

/** Verifica el comprobante de un solo flujo emitido por el adaptador WebAuthn tras validar la atestación. */
final class PasskeyAttestationVerifier
{
    public function verify(User $user, string $credentialIdentifier, string $publicKey, #[SensitiveParameter] string $attestationToken): bool
    {
        $key = (string) config('app.key');
        if ($key === '') {
            return false;
        }
        $expected = hash_hmac('sha256', implode('|', [
            'misvales-passkey-attestation-v1',
            $user->public_id,
            $credentialIdentifier,
            hash('sha256', $publicKey),
        ]), $key);

        return hash_equals($expected, $attestationToken);
    }
}
