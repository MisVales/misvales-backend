<?php

namespace App\Services\Auth;

use App\Models\User;
use CBOR\Decoder;
use CBOR\StringStream;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Key\Ec2Key;
use Cose\Key\Key;
use Cose\Key\RsaKey;
use Illuminate\Support\Str;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class WebAuthnService
{
    private $serializer;

    public function __construct()
    {
        $attestationManager = AttestationStatementSupportManager::create();
        $attestationManager->add(NoneAttestationStatementSupport::create());
        $factory = new WebauthnSerializerFactory($attestationManager);
        $this->serializer = $factory->create();
    }

    /**
     * Genera las opciones para crear una nueva credencial WebAuthn (Passkey)
     */
    public function generateRegistrationOptions(User $user): PublicKeyCredentialCreationOptions
    {
        $rpEntity = PublicKeyCredentialRpEntity::create(
            config('app.name'),
            parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost'
        );

        if (! $user->webauthn_user_handle) {
            $user->webauthn_user_handle = Str::uuid()->toString();
            $user->save();
        }

        $userEntity = PublicKeyCredentialUserEntity::create(
            $user->email,                // name
            $user->webauthn_user_handle, // id
            'MisVales - '.$user->name  // displayName
        );

        $challenge = random_bytes(32);

        $supportedAlgorithms = [
            PublicKeyCredentialParameters::create('public-key', ES256::ID),
            PublicKeyCredentialParameters::create('public-key', RS256::ID),
        ];

        return PublicKeyCredentialCreationOptions::create(
            $rpEntity,
            $userEntity,
            $challenge,
            $supportedAlgorithms,
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
            ),
            null, // attestation
            [],   // excludeCredentials
            60000 // timeout
        );
    }

    /**
     * Serializa las opciones a JSON para enviar al frontend
     */
    public function serializeOptions(PublicKeyCredentialCreationOptions $options): string
    {
        return $this->serializer->serialize($options, 'json');
    }

    /**
     * Valida la respuesta del cliente (Registration)
     */
    public function verifyRegistration(string $clientDataJSON, string $attestationObject, PublicKeyCredentialCreationOptions $options)
    {
        $attestationManager = AttestationStatementSupportManager::create();
        $attestationManager->add(NoneAttestationStatementSupport::create());

        $loader = AttestationObjectLoader::create($attestationManager);

        $attestation = $loader->load($attestationObject);

        // Validación básica manual del challenge y RP ID
        $clientData = json_decode($this->base64url_decode($clientDataJSON), true);

        $expectedChallengeBase64 = rtrim(strtr(base64_encode($options->challenge), '+/', '-_'), '=');

        if ($clientData['challenge'] !== $expectedChallengeBase64) {
            throw new \Exception('Invalid challenge.');
        }

        $expectedOrigin = config('app.url');
        if (str_contains($expectedOrigin, 'localhost') || str_contains(config('frontend.url', 'localhost'), 'localhost')) {
            // Bypass origin check for local dev
        } else {
            if ($clientData['origin'] !== $expectedOrigin) {
                throw new \Exception('Invalid origin.');
            }
        }

        $authData = $attestation->authData;
        $expectedRpIdHash = hash('sha256', $options->rp->id, true);
        if (! hash_equals($expectedRpIdHash, $authData->rpIdHash)) {
            throw new \Exception('Invalid RP ID.');
        }

        return $authData->attestedCredentialData;
    }

    /**
     * Genera opciones de autenticación (Login)
     */
    public function generateAuthenticationOptions(User $user, array $allowedCredentialsIds = []): PublicKeyCredentialRequestOptions
    {
        $rpId = parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';
        $challenge = random_bytes(32);

        $allowList = [];
        foreach ($allowedCredentialsIds as $id) {
            $allowList[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                base64_decode($id)
            );
        }

        return PublicKeyCredentialRequestOptions::create(
            $challenge,
            $rpId,
            $allowList,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            60000 // timeout
        );
    }

    /**
     * Serializa opciones de autenticación
     */
    public function serializeRequestOptions(PublicKeyCredentialRequestOptions $options): string
    {
        return $this->serializer->serialize($options, 'json');
    }

    /**
     * Valida la aserción de login manualmente (para evitar problemas de inyección de Repositories de webauthn-lib)
     */
    public function verifyAuthentication(string $clientDataJSON, string $authenticatorDataJSON, string $signature, string $credentialPublicKey, PublicKeyCredentialRequestOptions $options)
    {
        $clientData = json_decode($this->base64url_decode($clientDataJSON), true);

        $expectedChallengeBase64 = rtrim(strtr(base64_encode($options->challenge), '+/', '-_'), '=');

        if ($clientData['challenge'] !== $expectedChallengeBase64) {
            throw new \Exception('Invalid challenge.');
        }

        $expectedOrigin = config('app.url');
        if (! str_contains($expectedOrigin, 'localhost') && ! str_contains(config('frontend.url', 'localhost'), 'localhost')) {
            if ($clientData['origin'] !== $expectedOrigin) {
                throw new \Exception('Invalid origin.');
            }
        }

        $authDataRaw = $this->base64url_decode($authenticatorDataJSON);
        $rpIdHash = substr($authDataRaw, 0, 32);

        $expectedRpIdHash = hash('sha256', $options->rpId, true);
        if (! hash_equals($expectedRpIdHash, $rpIdHash)) {
            throw new \Exception('Invalid RP ID.');
        }

        $flags = ord($authDataRaw[32]);
        $isUserPresent = ($flags & 0x01) !== 0;

        if (! $isUserPresent) {
            throw new \Exception('User must be present.');
        }

        $clientDataHash = hash('sha256', $this->base64url_decode($clientDataJSON), true);

        $signedData = $authDataRaw.$clientDataHash;

        $publicKeyData = base64_decode($credentialPublicKey);

        $decoder = Decoder::create();
        $cbor = $decoder->decode(new StringStream($publicKeyData));
        $keyArray = $cbor->normalize();

        $key = Key::createFromData($keyArray);
        $signatureDecoded = $this->base64url_decode($signature);

        $verified = false;

        if ($key instanceof Ec2Key) {
            $pem = $key->toPublic()->asPEM();
            $alg = $key->alg();
            $hashAlgo = match ((int) $alg) {
                -7 => OPENSSL_ALGO_SHA256,
                -35 => OPENSSL_ALGO_SHA384,
                -36 => OPENSSL_ALGO_SHA512,
                default => throw new \Exception('Unsupported EC algorithm: '.$alg),
            };
            $verified = openssl_verify($signedData, $signatureDecoded, $pem, $hashAlgo) === 1;
        } elseif ($key instanceof RsaKey) {
            $pem = $key->toPublic()->asPEM();
            $alg = $key->alg();
            $hashAlgo = match ((int) $alg) {
                -257 => OPENSSL_ALGO_SHA256,
                -258 => OPENSSL_ALGO_SHA384,
                -259 => OPENSSL_ALGO_SHA512,
                -65535 => OPENSSL_ALGO_SHA1,
                default => throw new \Exception('Unsupported RSA algorithm: '.$alg),
            };
            $verified = openssl_verify($signedData, $signatureDecoded, $pem, $hashAlgo) === 1;
        } else {
            throw new \Exception('Unsupported key type.');
        }

        if (! $verified) {
            throw new \Exception('Invalid signature.');
        }

        // Parse signCount
        $signCount = unpack('N', substr($authDataRaw, 33, 4))[1];

        return $signCount;
    }

    private function base64url_decode(string $data): string
    {
        $normalized = str_replace(['-', '_'], ['+', '/'], $data);
        $mod4 = strlen($normalized) % 4;
        if ($mod4 > 0) {
            $normalized .= str_repeat('=', 4 - $mod4);
        }

        return base64_decode($normalized);
    }
}
