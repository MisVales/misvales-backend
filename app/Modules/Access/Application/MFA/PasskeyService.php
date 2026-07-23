<?php

namespace App\Modules\Access\Application\MFA;

use App\Models\User;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticationExtensions\AuthenticationExtension;
use Webauthn\AuthenticationExtensions\AuthenticationExtensionsClientInputs;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialSourceRepository;

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
            self::CACHE_PREFIX . $user->id,
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
        $options = Cache::pull(self::CACHE_PREFIX . $user->id);

        if (!$options) {
            throw new RuntimeException('El desafío de registro expiró o no existe. Intenta nuevamente.');
        }

        // Initialize Webauthn components
        $attestationStatementSupportManager = AttestationStatementSupportManager::create();
        $attestationStatementSupportManager->add(NoneAttestationStatementSupport::create());

        $attestationObjectLoader = AttestationObjectLoader::create($attestationStatementSupportManager);
        
        $publicKeyCredentialLoader = PublicKeyCredentialLoader::create($attestationObjectLoader);

        try {
            // Reconstruct the JSON as expected by the library
            $publicKeyCredential = $publicKeyCredentialLoader->loadArray([
                'id' => 'dummy', // Will be parsed from attestation
                'rawId' => 'dummy', 
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => $clientDataJson,
                    'attestationObject' => $attestationObject,
                ]
            ]);

            $response = $publicKeyCredential->getResponse();
            if (!$response instanceof AuthenticatorAttestationResponse) {
                throw new \Exception('Respuesta inválida.');
            }

            // Validate
            $validator = AuthenticatorAttestationResponseValidator::create($attestationStatementSupportManager);
            
            $publicKeyCredentialSource = $validator->check(
                $response,
                $options,
                parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost'
            );

        } catch (\Throwable $e) {
            throw new RuntimeException('La validación de la passkey falló: ' . $e->getMessage());
        }

        DB::transaction(function () use ($user, $publicKeyCredentialSource) {
            MfaCredential::query()->create([
                'user_id' => $user->id,
                'type' => MfaType::PASSKEY->value,
                'public_key_or_secret' => base64_encode($publicKeyCredentialSource->getCredentialPublicKey()),
                'metadata' => [
                    'credential_id' => base64_encode($publicKeyCredentialSource->getPublicKeyCredentialId()),
                    'aaguid' => $publicKeyCredentialSource->getAaguid()->toString(),
                    'counter' => $publicKeyCredentialSource->getCounter(),
                    'transports' => $publicKeyCredentialSource->getTransports(),
                ],
                'status' => 'ACTIVE',
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
                ->where('status', 'ACTIVE')
                ->get();

            $passkey = $activeFactors->firstWhere('id', $credentialId);
            
            if (!$passkey || $passkey->type !== MfaType::PASSKEY->value) {
                throw new RuntimeException('Credencial no encontrada.');
            }

            if ($activeFactors->count() === 1) {
                throw new RuntimeException('No se puede retirar el único factor de autenticación activo.');
            }

            $passkey->delete();
        });
    }
}
