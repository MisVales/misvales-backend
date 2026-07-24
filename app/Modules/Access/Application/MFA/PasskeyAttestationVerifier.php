<?php

namespace App\Modules\Access\Application\MFA;

use App\Models\User;

/**
 * Verifies WebAuthn passkey attestations and assertions.
 *
 * Per spec B05.1:
 * - Use maintained library compatible with PHP 8.5
 * - Configure rpId and origin exactly per environment
 * - Require local user verification
 * - Challenge is random, single-use, expires in 5 minutes
 * - Validate challenge, origin, rpId, type, signature, counter, and credential ownership
 * - Store credential ID, public key, counter, transports, minimal metadata
 * - Do NOT store fingerprint, face, or biometric data
 * - Allow multiple passkeys
 * - Add/remove passkey requires reauthentication
 * - Cannot remove last passkey if TOTP not confirmed
 *
 * This verifier fails closed until the WebAuthn framework is wired in.
 */
final class PasskeyAttestationVerifier
{
    public function __construct(
        private string $rpId,
        private string $rpName,
        private string $origin,
    ) {}

    /**
     * Generate WebAuthn creation options for passkey registration.
     *
     * @param  string  $userId  User's public ID in UUID format
     * @param  string  $userEmail  User's email
     * @param  string  $userName  User's display name
     * @return array<string, mixed> Options for navigator.credentials.create()
     */
    public function generateCreationOptions(string $userId, string $userEmail, string $userName): array
    {
        $challenge = bin2hex(random_bytes(32));

        return [
            'rp' => [
                'id' => $this->rpId,
                'name' => $this->rpName,
            ],
            'user' => [
                'id' => base64_encode($userId),
                'name' => $userEmail,
                'displayName' => $userName,
            ],
            'challenge' => base64_encode(hex2bin($challenge)),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256
                ['type' => 'public-key', 'alg' => -257], // RS256
            ],
            'timeout' => 300000, // 5 minutes per spec
            'userVerification' => 'required',
            'attestation' => 'none',
        ];
    }

    public function expectedOrigin(): string
    {
        return $this->origin;
    }

    /**
     * Verify a passkey attestation response during registration.
     *
     * Returns array with credential_identifier and public_key if valid.
     * Per spec, we must validate:
     * - Attestation signature
     * - Challenge matches
     * - Origin matches
     * - rpId matches
     *
     * @param  string  $credentialId  Credential ID from client
     * @param  string  $publicKey  Public key from attestation
     * @param  string  $attestationToken  Attestation object from client
     * @return bool True if attestation is valid
     */
    public function verify(User $user, string $credentialId, string $publicKey, string $attestationToken): bool
    {
        if (empty($credentialId) || empty($publicKey) || empty($attestationToken)) {
            return false;
        }

        return false;
    }

    /**
     * Verify a passkey assertion during login/reauthentication.
     *
     * Validates:
     * - Signature with stored public key
     * - Counter is greater than stored counter (prevent cloning)
     * - Challenge matches
     * - Origin matches
     * - rpId matches
     */
    public function verifyAssertion(
        string $credentialId,
        string $publicKey,
        string $assertion,
        int $storedCounter,
    ): bool {
        if (empty($credentialId) || empty($publicKey) || empty($assertion)) {
            return false;
        }

        return false;
    }
}
