<?php

namespace App\Modules\Access\Application\Authorization;

use App\Models\User;
use App\Modules\Access\Application\Accounts\AccountSecurityRecorder;
use App\Modules\Access\Application\MFA\TotpVerifier;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\ReauthenticationMethod;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use ParagonIE\ConstantTime\Base64UrlSafe;
use SensitiveParameter;

final readonly class ReauthenticationService
{
    public function __construct(
        private TotpVerifier $totp,
        private PasskeyAssertionValidator $passkeys,
        private AccountSecurityRecorder $recorder,
    ) {}

    /**
     * @return array{challenge_id: string, challenge: string, expires_at: string, allow_credentials: list<array{id: string, type: string}>}
     */
    public function beginPasskey(User $user, AuthSession $session, AuthorizationBinding $binding): array
    {
        $this->assertActiveContext($user, $session);
        $challengeId = bin2hex(random_bytes(32));
        $challenge = random_bytes(32);
        $expiresAt = now()->addMinutes($this->ttlMinutes());
        $credentials = MfaCredential::query()
            ->where('user_id', $user->id)
            ->where('type', MfaType::PASSKEY->value)
            ->where('state', 'ACTIVE')
            ->pluck('credential_identifier')
            ->filter(fn (mixed $credentialId): bool => is_string($credentialId))
            ->values()
            ->map(fn (string $id): array => ['id' => $id, 'type' => 'public-key'])
            ->all();

        if ($credentials === []) {
            throw new AccessRuleViolation(
                'La cuenta no tiene una passkey activa.',
                403,
                'REAUTHENTICATION_FAILED',
            );
        }

        Cache::put($this->challengeKey($challengeId), [
            'user_id' => $user->id,
            'session_id' => $session->id,
            'context_version' => $user->context_version,
            'binding' => $this->bindingSignature($binding),
            'challenge' => base64_encode($challenge),
        ], $expiresAt);

        return [
            'challenge_id' => $challengeId,
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'expires_at' => $expiresAt->toIso8601String(),
            'allow_credentials' => $credentials,
        ];
    }

    /**
     * @param  array<string, mixed>  $assertion
     * @return array{authorization_token: string, expires_at: string}
     */
    public function reauthenticateWithPasskey(
        User $user,
        AuthSession $session,
        AuthorizationBinding $binding,
        #[SensitiveParameter] string $challengeId,
        array $assertion,
    ): array {
        $this->assertActiveContext($user, $session);
        $challengeData = Cache::pull($this->challengeKey($challengeId));
        if (! is_array($challengeData)
            || (int) ($challengeData['user_id'] ?? 0) !== $user->id
            || (int) ($challengeData['session_id'] ?? 0) !== $session->id
            || (int) ($challengeData['context_version'] ?? 0) !== $user->context_version
            || ! hash_equals((string) ($challengeData['binding'] ?? ''), $this->bindingSignature($binding))) {
            $this->fail($user, $session, 'PASSKEY_CHALLENGE_INVALID');
        }

        $challenge = base64_decode((string) $challengeData['challenge'], true);
        if ($challenge === false || ! $this->passkeys->validate($user, $assertion, $challenge)) {
            $this->fail($user, $session, 'PASSKEY_ASSERTION_INVALID');
        }

        return $this->issue($user, $session, $binding, ReauthenticationMethod::PASSKEY);
    }

    /**
     * @return array{authorization_token: string, expires_at: string}
     */
    public function reauthenticateWithPasswordTotp(
        User $user,
        AuthSession $session,
        AuthorizationBinding $binding,
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $totpCode,
    ): array {
        $this->assertActiveContext($user, $session);
        $credential = MfaCredential::query()
            ->where('user_id', $user->id)
            ->where('type', MfaType::TOTP->value)
            ->where('state', 'ACTIVE')
            ->first();

        $passwordValid = is_string($user->password) && Hash::check(trim($password), $user->password);
        $totpValid = false;
        if ($credential !== null && is_string($credential->encrypted_secret)) {
            try {
                $secret = Crypt::decryptString($credential->encrypted_secret);
                $totpValid = $this->totp->verify($secret, $totpCode, hash('sha256', (string) $credential->id));
            } catch (\Throwable) {
                $totpValid = false;
            }
        }

        if (! $passwordValid || ! $totpValid) {
            $this->fail($user, $session, 'PASSWORD_TOTP_INVALID');
        }

        return $this->issue($user, $session, $binding, ReauthenticationMethod::PASSWORD_TOTP);
    }

    /**
     * @return array{authorization_token: string, expires_at: string}
     */
    private function issue(
        User $user,
        AuthSession $session,
        AuthorizationBinding $binding,
        ReauthenticationMethod $method,
    ): array {
        return DB::transaction(function () use ($user, $session, $binding, $method): array {
            $plainToken = bin2hex(random_bytes(32));
            $expiresAt = now()->addMinutes($this->ttlMinutes());
            ReauthAuthorization::query()->create([
                'user_id' => $user->id,
                'auth_session_id' => $session->id,
                'requester_user_id' => $user->id,
                'method' => $method->value,
                'action' => $binding->action->value,
                'resource_type' => $binding->resourceType,
                'record_id' => $binding->resourceId,
                'branch_id' => $binding->branchId,
                'parameters_hash' => $binding->parametersHash(),
                'context_version' => $user->context_version,
                'reason' => $binding->reason,
                'token_hash' => hash('sha256', $plainToken),
                'issued_at' => now(),
                'expires_at' => $expiresAt,
            ]);
            $this->recorder->audit('REAUTHENTICATION_SUCCEEDED', 'AUTHORIZED', $user, $user, [
                'method' => $method->value,
                'action' => $binding->action->value,
                'resource_type' => $binding->resourceType,
                'resource_id' => $binding->resourceId,
                'branch_id' => $binding->branchId,
                'session_id' => $session->id,
            ]);

            return [
                'authorization_token' => $plainToken,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }

    private function assertActiveContext(User $user, AuthSession $session): void
    {
        if ($session->user_id !== $user->id
            || $session->state !== 'ACTIVE'
            || $session->revoked_at !== null
            || $session->expires_at->isPast()
            || $session->context_version !== $user->context_version) {
            throw new AccessRuleViolation(
                'La sesión o su contexto ya no permiten reautenticación.',
                401,
                'REAUTHENTICATION_REQUIRED',
            );
        }
    }

    private function fail(User $user, AuthSession $session, string $rule): never
    {
        $this->recorder->audit('REAUTHENTICATION_FAILED', 'DENIED', $user, $user, [
            'rule' => $rule,
            'session_id' => $session->id,
        ]);

        throw new AccessRuleViolation(
            'No fue posible comprobar nuevamente la identidad.',
            401,
            'REAUTHENTICATION_FAILED',
        );
    }

    private function bindingSignature(AuthorizationBinding $binding): string
    {
        return hash('sha256', implode('|', [
            $binding->action->value,
            $binding->resourceType ?? '',
            $binding->resourceId ?? '',
            $binding->branchId ?? '',
            $binding->parametersHash(),
            $binding->reason ?? '',
        ]));
    }

    private function challengeKey(string $challengeId): string
    {
        return 'access:reauth:passkey:'.hash('sha256', $challengeId);
    }

    private function ttlMinutes(): int
    {
        return max(1, min(5, (int) config('access.tokens.reauthorization_ttl_minutes', 5)));
    }
}
