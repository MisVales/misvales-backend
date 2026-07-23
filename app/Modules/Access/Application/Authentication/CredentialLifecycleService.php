<?php

namespace App\Modules\Access\Application\Authentication;

use App\Models\User;
use App\Modules\Access\Application\Accounts\AccountSecurityRecorder;
use App\Modules\Access\Application\Accounts\ImmediateAccessRevoker;
use App\Modules\Access\Application\Accounts\InvitationIssuer;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Application\MFA\PasskeyAttestationVerifier;
use App\Modules\Access\Application\MFA\RecoveryCodeGenerator;
use App\Modules\Access\Application\MFA\TotpVerifier;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountInvitation;
use App\Modules\Access\Infrastructure\Persistence\Models\InvitationExchange;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaRecoveryCode;
use App\Modules\Access\Infrastructure\Persistence\Models\PasswordHistory;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

final readonly class CredentialLifecycleService
{
    public const GENERIC_RECOVERY_RESPONSE = 'Si la información corresponde a una cuenta elegible, se enviarán las instrucciones de recuperación.';

    public function __construct(
        private PasswordPolicy $passwords,
        private TotpVerifier $totp,
        private PasskeyAttestationVerifier $passkeys,
        private RecoveryCodeGenerator $recoveryCodes,
        private InvitationIssuer $invitations,
        private TemporaryAuthorization $authorization,
        private ImmediateAccessRevoker $revoker,
        private AccountSecurityRecorder $recorder,
    ) {}

    /** @return array{exchange_token:string,expires_at:string,purpose:string,confirmation_pending:bool,account:array{id:string,email:string,name:string}} */
    public function inspectInvitation(#[SensitiveParameter] string $plainToken): array
    {
        return DB::transaction(function () use ($plainToken): array {
            $invitation = $this->activeInvitation($plainToken, [InvitationPurpose::ACCOUNT_ACTIVATION, InvitationPurpose::ACCOUNT_REACTIVATION]);
            $user = User::query()->lockForUpdate()->findOrFail($invitation->user_id);
            $this->assertInvitationBinding($invitation, $user);

            InvitationExchange::query()->where('account_invitation_id', $invitation->id)
                ->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $plainExchange = bin2hex(random_bytes(32));
            $exchange = InvitationExchange::query()->create([
                'account_invitation_id' => $invitation->id,
                'token_hash' => hash('sha256', $plainExchange),
                'issued_at' => now(),
                'expires_at' => now()->addMinutes((int) config('access.security.invitation_exchange_ttl_minutes')),
                'prepared_at' => $user->password !== null && $user->mfa_enrolled_at !== null ? now() : null,
            ]);

            return [
                'exchange_token' => $plainExchange,
                'expires_at' => $exchange->expires_at->toIso8601String(),
                'purpose' => $invitation->purpose->value,
                'confirmation_pending' => $exchange->prepared_at !== null,
                'account' => ['id' => $user->public_id, 'email' => $user->email, 'name' => $user->name],
            ];
        });
    }

    /** @param array<string, mixed>|null $mfa
     * @return array{recovery_codes?:list<string>,confirmation_required:bool,login_required:bool}
     */
    public function completeInvitation(
        #[SensitiveParameter] string $exchangeToken,
        #[SensitiveParameter] ?string $password,
        ?array $mfa,
        bool $recoveryCodesConfirmed = false,
    ): array {
        return DB::transaction(function () use ($exchangeToken, $password, $mfa, $recoveryCodesConfirmed): array {
            $exchange = InvitationExchange::query()->where('token_hash', hash('sha256', $exchangeToken))->lockForUpdate()->first();
            if ($exchange === null || $exchange->used_at !== null || $exchange->revoked_at !== null || $exchange->expires_at->isPast()) {
                throw new AccessRuleViolation('El estado temporal de invitación no es válido.', 409);
            }
            $invitation = AccountInvitation::query()->lockForUpdate()->findOrFail($exchange->account_invitation_id);
            if ($invitation->state !== TokenState::ACTIVE || $invitation->expires_at->isPast()
                || ! in_array($invitation->purpose, [InvitationPurpose::ACCOUNT_ACTIVATION, InvitationPurpose::ACCOUNT_REACTIVATION], true)) {
                throw new AccessRuleViolation('La invitación ya no está activa.', 409);
            }
            $user = User::query()->lockForUpdate()->findOrFail($invitation->user_id);
            $this->assertInvitationBinding($invitation, $user);
            if ($user->state !== AccountState::PENDING_ACTIVATION) {
                throw new AccessRuleViolation('La cuenta no está pendiente de activación.', 409);
            }

            if ($exchange->prepared_at !== null) {
                if (! $recoveryCodesConfirmed) {
                    throw new AccessRuleViolation('Debe confirmar que guardó los códigos de recuperación.', 409);
                }
                $user->forceFill([
                    'state' => AccountState::ACTIVE,
                    'email_verified_at' => now(),
                    'activated_at' => now(),
                ])->save();
                $invitation->forceFill(['state' => TokenState::USED, 'used_at' => now()])->save();
                $exchange->forceFill(['used_at' => now()])->save();
                $this->recorder->audit('ACCOUNT_ACTIVATED', 'SUCCESS', $user, $user, ['purpose' => $invitation->purpose->value]);
                $this->recorder->outbox('ACCOUNT_ACTIVATED', "account-activated:{$user->public_id}:{$user->credential_version}", ['user_id' => $user->public_id]);

                return ['confirmation_required' => false, 'login_required' => true];
            }
            if ($recoveryCodesConfirmed || $password === null || $mfa === null) {
                throw new AccessRuleViolation('Primero debe establecer contraseña y MFA.', 409);
            }

            $normalized = $this->passwords->validateAndNormalize($user, $password);
            $this->enrollMfa($user, $mfa);
            $hash = Hash::make($normalized);
            $user->forceFill([
                'password' => $hash,
                'password_changed_at' => now(),
                'mfa_enrolled_at' => now(),
            ])->save();
            PasswordHistory::query()->create(['user_id' => $user->id, 'password_hash' => $hash, 'recorded_at' => now()]);
            $codes = $this->recoveryCodes->replaceFor($user);
            $exchange->forceFill(['prepared_at' => now()])->save();
            $this->recorder->audit('ACCOUNT_ACTIVATION_PREPARED', 'PENDING_CONFIRMATION', $user, $user);

            return ['recovery_codes' => $codes, 'confirmation_required' => true, 'login_required' => false];
        });
    }

    public function requestPasswordRecovery(string $email): string
    {
        $started = hrtime(true);
        $normalized = mb_strtolower(trim($email));
        DB::transaction(function () use ($normalized): void {
            $user = User::query()->where('normalized_email', $normalized)->lockForUpdate()->first();
            if ($user === null || $user->state !== AccountState::ACTIVE || $user->password === null || $user->mfa_enrolled_at === null) {
                return;
            }
            $this->invitations->currentOrIssue($user, InvitationPurpose::PASSWORD_RECOVERY, (int) config('access.tokens.password_recovery_ttl_minutes'));
            $this->recorder->audit('PASSWORD_RECOVERY_REQUESTED', 'ACCEPTED', null, $user);
        });
        $elapsedMicroseconds = intdiv(hrtime(true) - $started, 1000);
        if ($elapsedMicroseconds < 50_000) {
            usleep(50_000 - $elapsedMicroseconds);
        }

        return self::GENERIC_RECOVERY_RESPONSE;
    }

    /** @param array<string, mixed>|null $replacementMfa */
    /** @return array{login_required:bool,recovery_codes?:list<string>} */
    public function completeRecovery(
        #[SensitiveParameter] string $plainToken,
        #[SensitiveParameter] string $password,
        string $factorType,
        #[SensitiveParameter] string $factorValue,
        ?array $replacementMfa = null,
    ): array {
        return DB::transaction(function () use ($plainToken, $password, $factorType, $factorValue, $replacementMfa): array {
            $invitation = $this->activeInvitation($plainToken, [InvitationPurpose::PASSWORD_RECOVERY, InvitationPurpose::ACCOUNT_RECOVERY]);
            $user = User::query()->lockForUpdate()->findOrFail($invitation->user_id);
            $this->assertInvitationBinding($invitation, $user);
            if ($invitation->purpose === InvitationPurpose::PASSWORD_RECOVERY) {
                $this->verifySecondFactor($user, $factorType, $factorValue);
            } elseif ($replacementMfa === null) {
                throw new AccessRuleViolation('La recuperación administrativa requiere reinscripción MFA.');
            }

            $normalized = $this->passwords->validateAndNormalize($user, $password);
            if ($invitation->purpose === InvitationPurpose::ACCOUNT_RECOVERY) {
                $this->enrollMfa($user, $replacementMfa ?? []);
            }
            $this->recordExistingPassword($user);
            $hash = Hash::make($normalized);
            $invitation->forceFill(['state' => TokenState::USED, 'used_at' => now()])->save();
            $user->forceFill([
                'password' => $hash,
                'state' => AccountState::ACTIVE,
                'credential_version' => $invitation->purpose === InvitationPurpose::PASSWORD_RECOVERY ? $user->credential_version + 1 : $user->credential_version,
                'context_version' => $user->context_version + 1,
                'password_changed_at' => now(),
                'mfa_enrolled_at' => now(),
            ])->save();
            PasswordHistory::query()->create(['user_id' => $user->id, 'password_hash' => $hash, 'recorded_at' => now()]);
            $this->revoker->revoke($user);
            $codes = $invitation->purpose === InvitationPurpose::ACCOUNT_RECOVERY ? $this->recoveryCodes->replaceFor($user) : null;
            $this->recorder->audit('PASSWORD_RECOVERY_COMPLETED', 'SUCCESS', $user, $user, ['purpose' => $invitation->purpose->value, 'risk' => 'HIGH']);
            $this->recorder->outbox('PASSWORD_RECOVERY_COMPLETED', "password-recovered:{$user->public_id}:{$user->credential_version}", ['user_id' => $user->public_id, 'risk' => 'HIGH']);

            return $codes === null
                ? ['login_required' => true]
                : ['login_required' => true, 'recovery_codes' => $codes];
        });
    }

    public function changePassword(User $user, #[SensitiveParameter] string $password, #[SensitiveParameter] string $reauthToken): void
    {
        DB::transaction(function () use ($user, $password, $reauthToken): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->state !== AccountState::ACTIVE) {
                throw new AccessRuleViolation('La cuenta no está activa.', 403);
            }
            $this->authorization->consumeReauth($user, $reauthToken, 'password.change', $user->public_id);
            $normalized = $this->passwords->validateAndNormalize($user, $password);
            $this->recordExistingPassword($user);
            $hash = Hash::make($normalized);
            $user->forceFill([
                'password' => $hash,
                'password_changed_at' => now(),
                'credential_version' => $user->credential_version + 1,
                'context_version' => $user->context_version + 1,
            ])->save();
            PasswordHistory::query()->create(['user_id' => $user->id, 'password_hash' => $hash, 'recorded_at' => now()]);
            $this->revoker->revoke($user);
            $this->recorder->audit('PASSWORD_CHANGED', 'SUCCESS', $user, $user);
            $this->recorder->outbox('PASSWORD_CHANGED', "password-changed:{$user->public_id}:{$user->credential_version}", ['user_id' => $user->public_id]);
        });
    }

    /** @param list<InvitationPurpose> $purposes */
    private function activeInvitation(#[SensitiveParameter] string $plainToken, array $purposes): AccountInvitation
    {
        $invitation = AccountInvitation::query()->where('token_hash', hash('sha256', $plainToken))->lockForUpdate()->first();
        if ($invitation === null || $invitation->state !== TokenState::ACTIVE || $invitation->expires_at->isPast() || ! in_array($invitation->purpose, $purposes, true)) {
            throw new AccessRuleViolation('El token no está activo para este propósito.', 409);
        }

        return $invitation;
    }

    private function assertInvitationBinding(AccountInvitation $invitation, User $user): void
    {
        if (! hash_equals($invitation->email_hash, hash('sha256', $user->normalized_email)) || $invitation->credential_version !== $user->credential_version) {
            throw new AccessRuleViolation('La invitación ya no corresponde a las credenciales actuales.', 409);
        }
    }

    /** @param array<string, mixed> $mfa */
    private function enrollMfa(User $user, array $mfa): void
    {
        $type = MfaType::tryFrom((string) ($mfa['type'] ?? ''));
        if ($type === MfaType::TOTP) {
            $secret = (string) ($mfa['secret'] ?? '');
            if (! $this->totp->verify($secret, (string) ($mfa['code'] ?? ''))) {
                throw new AccessRuleViolation('El código TOTP de inscripción no es válido.');
            }
            MfaCredential::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['state' => 'REVOKED', 'revoked_at' => now()]);
            MfaCredential::query()->create([
                'user_id' => $user->id,
                'type' => MfaType::TOTP,
                'credential_identifier' => hash('sha256', $secret),
                'encrypted_secret' => $secret,
                'state' => 'ACTIVE',
            ]);

            return;
        }
        if ($type === MfaType::PASSKEY
            && is_string($mfa['credential_identifier'] ?? null)
            && is_string($mfa['public_key'] ?? null)
            && is_string($mfa['attestation_token'] ?? null)
            && $this->passkeys->verify($user, $mfa['credential_identifier'], $mfa['public_key'], $mfa['attestation_token'])) {
            MfaCredential::query()->create([
                'user_id' => $user->id,
                'type' => MfaType::PASSKEY,
                'credential_identifier' => $mfa['credential_identifier'],
                'public_key' => $mfa['public_key'],
                'metadata' => ['attestation' => 'verified'],
                'state' => 'ACTIVE',
            ]);

            return;
        }
        throw new AccessRuleViolation('Se requiere una inscripción MFA válida.');
    }

    private function verifySecondFactor(User $user, string $type, #[SensitiveParameter] string $value): void
    {
        if ($type === 'TOTP') {
            $credential = MfaCredential::query()->where('user_id', $user->id)->where('type', MfaType::TOTP->value)->where('state', 'ACTIVE')->first();
            if ($credential !== null && is_string($credential->encrypted_secret) && $this->totp->verify($credential->encrypted_secret, $value)) {
                return;
            }
        }
        if ($type === 'RECOVERY_CODE') {
            $code = MfaRecoveryCode::query()->where('user_id', $user->id)->where('code_hash', hash('sha256', strtoupper($value)))
                ->whereNull('used_at')->whereNull('revoked_at')->lockForUpdate()->first();
            if ($code !== null) {
                $code->forceFill(['used_at' => now()])->save();

                return;
            }
        }
        if ($type === 'PASSKEY_AUTHORIZATION') {
            $authorization = ReauthAuthorization::query()->where('user_id', $user->id)->where('action', 'password.recovery.passkey')
                ->where('token_hash', hash('sha256', $value))->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            if ($authorization !== null) {
                $authorization->forceFill(['used_at' => now()])->save();

                return;
            }
        }
        throw new AccessRuleViolation('El segundo factor no es válido.', 403);
    }

    private function recordExistingPassword(User $user): void
    {
        if ($user->password !== null && ! PasswordHistory::query()->where('user_id', $user->id)->where('password_hash', $user->password)->exists()) {
            PasswordHistory::query()->create(['user_id' => $user->id, 'password_hash' => $user->password, 'recorded_at' => now()]);
        }
    }
}
