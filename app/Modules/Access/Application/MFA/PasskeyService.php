<?php

namespace App\Modules\Access\Application\MFA;

use App\Models\User;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CollectedClientData;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final class PasskeyService
{
    private const CACHE_PREFIX = 'passkey_registration:';

    /**
     * Generates WebAuthn registration options for a user.
     */
    public function generateOptions(User $user): PublicKeyCredentialCreationOptions
    {
        $rpEntity = new PublicKeyCredentialRpEntity(
            config('app.name', 'MisVales'),
            parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost'
        );

        $userEntity = new PublicKeyCredentialUserEntity(
            $user->email,
            (string) $user->id,
            $user->name ?? $user->email
        );

        $challenge = random_bytes(32);

        $pubKeyCredParams = [
            PublicKeyCredentialParameters::create('public-key', -7), // ES256
            PublicKeyCredentialParameters::create('public-key', -257), // RS256
        ];

        // Ensure user verification is required (local biometrics/PIN)
        $authenticatorSelection = AuthenticatorSelectionCriteria::create(
            null,
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED
        );

        $options = PublicKeyCredentialCreationOptions::create(
            $rpEntity,
            $userEntity,
            $challenge,
            $pubKeyCredParams,
            $authenticatorSelection,
            null,
            [],
            300000
        );

        // Save options to Cache for 5 minutes
        Cache::put(
            self::CACHE_PREFIX.$user->id,
            $options,
            now()->addMinutes(5)
        );

        return $options;
    }

    /**
     * Validates the WebAuthn response and registers the passkey.
     */
    public function register(User $user, string $clientDataJson, string $attestationObject): void
    {
        /** @var PublicKeyCredentialCreationOptions|null $options */
        $options = Cache::pull(self::CACHE_PREFIX.$user->id);

        if (! $options) {
            throw new RuntimeException('El desafío de registro expiró o no existe. Intenta nuevamente.');
        }

        // Initialize Webauthn components
        $attestationStatementSupportManager = AttestationStatementSupportManager::create();
        $attestationStatementSupportManager->add(NoneAttestationStatementSupport::create());

        try {
            $response = AuthenticatorAttestationResponse::create(
                CollectedClientData::createFormJson($clientDataJson),
                AttestationObjectLoader::create($attestationStatementSupportManager)->load($attestationObject),
            );
            $ceremonyFactory = new CeremonyStepManagerFactory;
            $ceremonyFactory->setAttestationStatementSupportManager($attestationStatementSupportManager);
            $ceremonyFactory->setAllowedOrigins([(string) config('app.url')]);
            $validator = AuthenticatorAttestationResponseValidator::create($ceremonyFactory->creationCeremony());

            $publicKeyCredentialSource = $validator->check(
                $response,
                $options,
                parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost'
            );

        } catch (\Throwable $e) {
            throw new RuntimeException('La validación de la passkey falló: '.$e->getMessage());
        }

        DB::transaction(function () use ($user, $publicKeyCredentialSource) {
            MfaCredential::query()->create([
                'user_id' => $user->id,
                'type' => MfaType::PASSKEY->value,
                'credential_identifier' => base64_encode($publicKeyCredentialSource->publicKeyCredentialId),
                'public_key' => base64_encode($publicKeyCredentialSource->credentialPublicKey),
                'signature_counter' => $publicKeyCredentialSource->counter,
                'metadata' => [
                    'aaguid' => (string) $publicKeyCredentialSource->aaguid,
                    'transports' => $publicKeyCredentialSource->transports,
                ],
                'state' => 'ACTIVE',
                'registered_at' => now(),
            ]);
        });
    }

    /**
     * Removes a passkey credential.
     */
    public function destroy(User $user, int $credentialId): void
    {
        DB::transaction(function () use ($user, $credentialId) {
            $activeFactors = MfaCredential::query()
                ->where('user_id', $user->id)
                ->where('state', 'ACTIVE')
                ->get();

            $passkey = $activeFactors->firstWhere('id', $credentialId);

            if ($passkey === null || $passkey->type !== MfaType::PASSKEY) {
                throw new RuntimeException('Credencial no encontrada.');
            }

            if ($activeFactors->count() === 1) {
                throw new RuntimeException('No se puede retirar el único factor de autenticación activo.');
            }

            $passkey->delete();
        });
    }
}
