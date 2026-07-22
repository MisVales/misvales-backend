<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Accounts\AccountRequestState;
use App\Modules\Access\Domain\Accounts\AccountRequestType;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountRequest;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaRecoveryCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Normalizer;

final readonly class AccountLifecycleService
{
    private const BRANCH_CREATABLE_ROLES = [RoleCode::COORDINATOR, RoleCode::VERIFIER, RoleCode::CASHIER];

    public function __construct(
        private InvitationIssuer $invitations,
        private TemporaryAuthorization $authorization,
        private ImmediateAccessRevoker $revoker,
        private AccountSecurityRecorder $recorder,
    ) {}

    /** @param array{name:string,email:string,role:RoleCode,branch:?Branch} $data */
    public function createDirect(User $actor, array $data, string $authorizationToken): User
    {
        return DB::transaction(function () use ($actor, $data, $authorizationToken): User {
            $this->requireGeneralManager($actor);
            $normalized = $this->normalizedAccountData($data);
            $this->authorization->consumeOperational($actor, $authorizationToken, 'account.create', $normalized['captured']);

            $user = $this->createPendingAccount($normalized['name'], $normalized['email'], $normalized['role'], $normalized['branch']);
            $this->recorder->audit('ACCOUNT_CREATED_DIRECTLY', 'SUCCESS', $actor, $user, [
                'executor_id' => $actor->public_id,
                'role' => $normalized['role']->value,
            ]);

            return $user;
        });
    }

    /** @param array{name:string,email:string,role:RoleCode,reason:string,idempotency_key:string} $data */
    public function requestBranchCreation(User $actor, array $data, string $reauthToken): AccountRequest
    {
        return DB::transaction(function () use ($actor, $data, $reauthToken): AccountRequest {
            $this->requireBranchManager($actor);
            if (! in_array($data['role'], self::BRANCH_CREATABLE_ROLES, true)) {
                throw new AccessRuleViolation('El rol solicitado no está permitido para una gerencia de sucursal.', 403);
            }

            $this->authorization->consumeReauth($actor, $reauthToken, 'account.request.create');
            $email = $this->normalizeEmail($data['email']);
            $this->ensureEmailAvailable($email);
            $role = $this->role($data['role']);

            $request = AccountRequest::query()->firstOrCreate(
                ['idempotency_key' => $data['idempotency_key']],
                [
                    'type' => AccountRequestType::CREATE,
                    'target_email' => $email,
                    'target_name' => $this->normalizeName($data['name']),
                    'requested_role_id' => $role->id,
                    'branch_id' => $actor->branch_id,
                    'requested_by' => $actor->id,
                    'reason' => $data['reason'],
                ],
            );

            $this->recordRequest($request, $actor);

            return $request;
        });
    }

    public function requestLifecycleAction(User $actor, User $target, AccountRequestType $type, string $reason, string $idempotencyKey, string $reauthToken): AccountRequest
    {
        return DB::transaction(function () use ($actor, $target, $type, $reason, $idempotencyKey, $reauthToken): AccountRequest {
            $this->requireBranchManager($actor);
            if ($actor->branch_id !== $target->branch_id) {
                throw new AccessRuleViolation('La cuenta no pertenece a la sucursal del solicitante.', 403);
            }
            if ($type === AccountRequestType::CREATE) {
                throw new AccessRuleViolation('El tipo de solicitud no es válido.');
            }

            $this->authorization->consumeReauth($actor, $reauthToken, "account.request.{$type->value}", $target->public_id);
            $request = AccountRequest::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'type' => $type,
                    'target_user_id' => $target->id,
                    'branch_id' => $actor->branch_id,
                    'requested_by' => $actor->id,
                    'reason' => $reason,
                ],
            );

            $this->recordRequest($request, $actor);

            return $request;
        });
    }

    public function decide(User $actor, AccountRequest $request, AccountRequestState $decision, string $reason, string $reauthToken): AccountRequest
    {
        return DB::transaction(function () use ($actor, $request, $decision, $reason, $reauthToken): AccountRequest {
            $this->requireGeneralManager($actor);
            $request = AccountRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->state === $decision) {
                return $request;
            }
            if ($request->state !== AccountRequestState::PENDING_APPROVAL) {
                throw new AccessRuleViolation('La solicitud ya tiene una decisión final.', 409);
            }
            if ($request->requested_by === $actor->id) {
                throw new AccessRuleViolation('El solicitante y el autorizador deben ser cuentas distintas.', 403);
            }
            if (! in_array($decision, [AccountRequestState::APPROVED, AccountRequestState::REJECTED], true)) {
                throw new AccessRuleViolation('La decisión no es válida.');
            }

            $action = $decision === AccountRequestState::APPROVED ? 'approve' : 'reject';
            $this->authorization->consumeReauth($actor, $reauthToken, "account.request.{$action}", $request->public_id);

            $result = null;
            if ($decision === AccountRequestState::APPROVED) {
                $result = $this->executeApprovedRequest($request, $actor);
            }

            $request->forceFill([
                'state' => $decision,
                'decision' => $decision->value,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => $reason,
                'result_user_id' => $result?->id,
            ])->save();

            $target = $result ?? ($request->target_user_id ? User::query()->find($request->target_user_id) : null);
            $this->recorder->audit('ACCOUNT_REQUEST_DECIDED', $decision->value, $actor, $target, [
                'request_id' => $request->public_id,
                'requester_id' => User::query()->find($request->requested_by)?->public_id,
                'authorizer_id' => $actor->public_id,
                'executor_id' => $actor->public_id,
            ]);
            $this->recorder->outbox('ACCOUNT_REQUEST_DECIDED', "account-request-decision:{$request->public_id}", [
                'request_id' => $request->public_id,
                'decision' => $decision->value,
            ]);

            return $request->refresh();
        });
    }

    public function disableDirect(User $actor, User $target, string $reason, string $reauthToken): User
    {
        return DB::transaction(function () use ($actor, $target, $reason, $reauthToken): User {
            $this->requireGeneralManager($actor);
            $this->authorization->consumeReauth($actor, $reauthToken, 'account.disable', $target->public_id);

            return $this->disable($target, $actor, $reason);
        });
    }

    public function reactivateDirect(User $actor, User $target, string $reason, bool $compromise, string $reauthToken): User
    {
        return DB::transaction(function () use ($actor, $target, $reason, $compromise, $reauthToken): User {
            $this->requireGeneralManager($actor);
            $this->authorization->consumeReauth($actor, $reauthToken, 'account.reactivate', $target->public_id);

            return $this->reactivate($target, $actor, $reason, $compromise);
        });
    }

    public function recoveryDirect(User $actor, User $target, string $reason, string $reauthToken): User
    {
        return DB::transaction(function () use ($actor, $target, $reason, $reauthToken): User {
            $this->requireGeneralManager($actor);
            $this->authorization->consumeReauth($actor, $reauthToken, 'account.recovery', $target->public_id);

            return $this->initiateRecovery($target, $actor, $reason);
        });
    }

    public function resendInvitation(User $actor, User $target, string $reauthToken): User
    {
        return DB::transaction(function () use ($actor, $target, $reauthToken): User {
            $this->requireGeneralManager($actor);
            if ($target->state !== AccountState::PENDING_ACTIVATION) {
                throw new AccessRuleViolation('Solo una cuenta pendiente de activación puede recibir otra invitación.', 409);
            }
            $this->authorization->consumeReauth($actor, $reauthToken, 'account.invitation.resend', $target->public_id);
            $this->invitations->issue($target, InvitationPurpose::ACCOUNT_ACTIVATION);
            $this->recorder->audit('ACCOUNT_INVITATION_RESENT', 'SUCCESS', $actor, $target);

            return $target;
        });
    }

    private function executeApprovedRequest(AccountRequest $request, User $actor): User
    {
        return match ($request->type) {
            AccountRequestType::CREATE => $this->createPendingAccount(
                (string) $request->target_name,
                (string) $request->target_email,
                Role::query()->findOrFail($request->requested_role_id)->code,
                Branch::query()->findOrFail($request->branch_id),
            ),
            AccountRequestType::DISABLE => $this->disable(User::query()->findOrFail($request->target_user_id), $actor, $request->reason),
            AccountRequestType::REACTIVATE => $this->reactivate(User::query()->findOrFail($request->target_user_id), $actor, $request->reason, false),
            AccountRequestType::RECOVERY => $this->initiateRecovery(User::query()->findOrFail($request->target_user_id), $actor, $request->reason),
        };
    }

    private function createPendingAccount(string $name, string $email, RoleCode $roleCode, ?Branch $branch): User
    {
        $email = $this->normalizeEmail($email);
        $name = $this->normalizeName($name);
        $this->ensureEmailAvailable($email);
        if ($roleCode === RoleCode::DISTRIBUTOR) {
            throw new AccessRuleViolation('Una distribuidora solo puede crearse por autorización final.', 403);
        }

        $role = $this->role($roleCode);
        $this->validateBranch($roleCode, $branch);

        try {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'normalized_email' => $email,
                'password' => null,
                'role_id' => $role->id,
                'branch_id' => $branch?->id,
                'state' => AccountState::PENDING_ACTIVATION,
                'context_version' => 1,
                'credential_version' => 1,
                'invited_at' => now(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw new AccessRuleViolation('El correo ya está asociado a una cuenta.', 409);
        }

        $this->invitations->issue($user, InvitationPurpose::ACCOUNT_ACTIVATION);

        return $user;
    }

    private function disable(User $target, User $actor, string $reason): User
    {
        $target = User::query()->with('role')->lockForUpdate()->findOrFail($target->id);
        if ($target->state === AccountState::DISABLED) {
            return $target;
        }
        if ($target->role->code === RoleCode::GENERAL_MANAGER && $target->state === AccountState::ACTIVE) {
            $hasAnother = User::query()->where('role_id', $target->role_id)->where('state', AccountState::ACTIVE->value)->whereKeyNot($target->id)->exists();
            if (! $hasAnother) {
                throw new AccessRuleViolation('No se puede deshabilitar al último gerente general activo.', 409);
            }
        }

        $target->forceFill([
            'state' => AccountState::DISABLED,
            'context_version' => $target->context_version + 1,
            'disabled_at' => now(),
        ])->save();
        $this->revoker->revoke($target);
        $this->recordLifecycle('ACCOUNT_DISABLED', $target, $actor, $reason);

        return $target;
    }

    private function reactivate(User $target, User $actor, string $reason, bool $compromise): User
    {
        $target = User::query()->lockForUpdate()->findOrFail($target->id);
        if ($target->state !== AccountState::DISABLED) {
            throw new AccessRuleViolation('Solo una cuenta deshabilitada puede reactivarse.', 409);
        }

        $target->forceFill([
            'state' => AccountState::PENDING_ACTIVATION,
            'credential_version' => $target->credential_version + 1,
            'password' => null,
            'disabled_at' => null,
            'invited_at' => now(),
            'mfa_enrolled_at' => null,
        ])->save();
        if ($compromise) {
            MfaCredential::query()->where('user_id', $target->id)->whereNull('revoked_at')->update(['state' => 'REVOKED', 'revoked_at' => now()]);
            MfaRecoveryCode::query()->where('user_id', $target->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
        $this->invitations->issue($target, InvitationPurpose::ACCOUNT_REACTIVATION);
        $this->recordLifecycle('ACCOUNT_REACTIVATED', $target, $actor, $reason);

        return $target;
    }

    private function initiateRecovery(User $target, User $actor, string $reason): User
    {
        $target = User::query()->lockForUpdate()->findOrFail($target->id);
        if ($target->state === AccountState::ACTIVE) {
            $target->forceFill(['state' => AccountState::SECURITY_SUSPENDED])->save();
        }
        if ($target->state === AccountState::SECURITY_SUSPENDED) {
            $target->forceFill(['state' => AccountState::PENDING_ACTIVATION])->save();
        }
        $target->forceFill([
            'context_version' => $target->context_version + 1,
            'credential_version' => $target->credential_version + 1,
            'password' => null,
            'mfa_enrolled_at' => null,
        ])->save();
        $this->revoker->revoke($target);
        MfaCredential::query()->where('user_id', $target->id)->whereNull('revoked_at')->update(['state' => 'REVOKED', 'revoked_at' => now()]);
        MfaRecoveryCode::query()->where('user_id', $target->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->invitations->issue($target, InvitationPurpose::ACCOUNT_RECOVERY);
        $this->recordLifecycle('ADMINISTRATIVE_RECOVERY_INITIATED', $target, $actor, $reason, 'HIGH');

        return $target;
    }

    /** @param array{name:string,email:string,role:RoleCode,branch:?Branch} $data
     * @return array{name:string,email:string,role:RoleCode,branch:?Branch,captured:array<string,mixed>}
     */
    private function normalizedAccountData(array $data): array
    {
        $name = $this->normalizeName($data['name']);
        $email = $this->normalizeEmail($data['email']);
        $this->validateBranch($data['role'], $data['branch']);

        return [
            'name' => $name,
            'email' => $email,
            'role' => $data['role'],
            'branch' => $data['branch'],
            'captured' => ['name' => $name, 'email' => $email, 'role' => $data['role']->value, 'branch_id' => $data['branch']?->public_id],
        ];
    }

    private function validateBranch(RoleCode $role, ?Branch $branch): void
    {
        if ($role->isGlobal() && $branch !== null) {
            throw new AccessRuleViolation('Un rol global no puede pertenecer a una sucursal.');
        }
        if (! $role->isGlobal() && ($branch === null || ! $branch->is_active)) {
            throw new AccessRuleViolation('El rol requiere una sucursal activa.');
        }
    }

    private function recordRequest(AccountRequest $request, User $actor): void
    {
        $this->recorder->audit('ACCOUNT_REQUESTED', 'PENDING_APPROVAL', $actor, null, ['request_id' => $request->public_id, 'type' => $request->type->value]);
        $this->recorder->outbox('ACCOUNT_REQUEST_PENDING', "account-request:{$request->public_id}", ['request_id' => $request->public_id, 'type' => $request->type->value]);
    }

    private function recordLifecycle(string $event, User $target, User $actor, string $reason, string $risk = 'NORMAL'): void
    {
        $this->recorder->audit($event, 'SUCCESS', $actor, $target, ['reason' => $reason, 'risk' => $risk]);
        $this->recorder->outbox($event, strtolower(str_replace('_', '-', $event)).":{$target->public_id}:{$target->context_version}", [
            'user_id' => $target->public_id,
            'branch_id' => $target->branch?->public_id,
            'audience' => ['TARGET', 'BRANCH_MANAGER', 'GENERAL_MANAGER'],
            'risk' => $risk,
        ]);
    }

    private function requireGeneralManager(User $actor): void
    {
        if ($actor->role?->code !== RoleCode::GENERAL_MANAGER || $actor->state !== AccountState::ACTIVE) {
            throw new AccessRuleViolation('Se requiere una cuenta activa de gerente general.', 403);
        }
    }

    private function requireBranchManager(User $actor): void
    {
        if ($actor->role?->code !== RoleCode::SUCURSAL_MANAGER || $actor->state !== AccountState::ACTIVE || $actor->branch_id === null) {
            throw new AccessRuleViolation('Se requiere una gerencia de sucursal activa.', 403);
        }
    }

    private function role(RoleCode $code): Role
    {
        return Role::query()->where('code', $code->value)->where('is_active', true)->firstOrFail();
    }

    private function ensureEmailAvailable(string $email): void
    {
        if (User::query()->where('normalized_email', $email)->exists()) {
            throw new AccessRuleViolation('El correo ya está asociado a una cuenta.', 409);
        }
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim(Normalizer::normalize($email, Normalizer::FORM_C) ?: $email));
    }

    private function normalizeName(string $name): string
    {
        return trim(Normalizer::normalize($name, Normalizer::FORM_C) ?: $name);
    }
}
