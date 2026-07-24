<?php

namespace App\Modules\Access\Application\Authorization;

use App\Models\User;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;

final class WebAuthnPasskeyAssertionValidator implements PasskeyAssertionValidator
{
    public function validate(User $user, array $assertion, string $challenge): bool
    {
        try {
            $rawId = (string) ($assertion['rawId'] ?? '');
            $assertionId = (string) ($assertion['id'] ?? '');
            $credentialId = Base64UrlSafe::decodeNoPadding($rawId);
            $credential = MfaCredential::query()
                ->where('user_id', $user->id)
                ->where('type', MfaType::PASSKEY->value)
                ->where('state', 'ACTIVE')
                ->where(function ($query) use ($assertionId, $rawId): void {
                    $query->where('credential_identifier', $rawId)
                        ->orWhere('credential_identifier', $assertionId);
                })
                ->lockForUpdate()
                ->first();

            if ($credential === null || $credential->public_key === null) {
                return false;
            }

            $serializer = (new WebauthnSerializerFactory(
                AttestationStatementSupportManager::create(),
            ))->create();
            $publicKeyCredential = $serializer->deserialize(
                json_encode($assertion, JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
                'json',
            );

            if (! $publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
                return false;
            }

            $rawMetadata = $credential->getRawOriginal('metadata');
            $metadata = is_string($rawMetadata)
                ? json_decode($rawMetadata, true, flags: JSON_THROW_ON_ERROR)
                : (is_array($rawMetadata) ? $rawMetadata : []);
            $aaguid = is_string($metadata['aaguid'] ?? null)
                ? $metadata['aaguid']
                : '00000000-0000-0000-0000-000000000000';
            $transports = array_values(array_filter(
                is_array($metadata['transports'] ?? null) ? $metadata['transports'] : [],
                is_string(...),
            ));
            $storedPublicKey = base64_decode((string) $credential->public_key, true);
            $record = CredentialRecord::create(
                $credentialId,
                'public-key',
                $transports,
                'none',
                EmptyTrustPath::create(),
                Uuid::fromString($aaguid),
                $storedPublicKey === false ? (string) $credential->public_key : $storedPublicKey,
                (string) $user->id,
                (int) $credential->signature_counter,
            );
            $rpId = (string) config('access.webauthn.rp_id', parse_url((string) config('app.url'), PHP_URL_HOST));
            $origin = (string) config('access.webauthn.origin', config('app.url'));
            $options = PublicKeyCredentialRequestOptions::create(
                challenge: $challenge,
                rpId: $rpId,
                allowCredentials: [PublicKeyCredentialDescriptor::create('public-key', $credentialId, $transports)],
                userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                timeout: 300000,
            );
            $factory = new CeremonyStepManagerFactory;
            $factory->setAllowedOrigins([$origin]);
            $validated = AuthenticatorAssertionResponseValidator::create($factory->requestCeremony())
                ->check(
                    $record,
                    $publicKeyCredential->response,
                    $options,
                    $rpId,
                    (string) $user->id,
                );

            $credential->forceFill(['signature_counter' => $validated->counter])->save();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
