<?php

namespace App\Modules\Access\Application\Authorization;

use App\Models\User;
use App\Modules\Access\Application\Accounts\AccountSecurityRecorder;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\OperationalAuthorizationToken;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class OperationalAuthorizationService
{
    public function __construct(
        private TemporaryAuthorization $reauth,
        private AccountSecurityRecorder $recorder,
    ) {}

    /**
     * @return array{operational_token: string, expires_at: string}
     */
    public function authorize(
        User $requester,
        User $authorizer,
        User $executor,
        AuthSession $authorizerSession,
        AuthorizationBinding $operation,
        #[SensitiveParameter] string $reauthToken,
    ): array {
        if ($requester->is($authorizer)) {
            throw new AccessRuleViolation(
                'El solicitante no puede autorizar su propia operación.',
                403,
                'SEPARATION_OF_DUTIES_REQUIRED',
            );
        }

        if ($authorizerSession->user_id !== $authorizer->id) {
            throw new AccessRuleViolation(
                'La sesión del autorizador no es válida.',
                403,
                'REAUTHENTICATION_REQUIRED',
            );
        }

        return DB::transaction(function () use (
            $requester,
            $authorizer,
            $executor,
            $authorizerSession,
            $operation,
            $reauthToken,
        ): array {
            $this->reauth->consume($authorizer, $reauthToken, new AuthorizationBinding(
                action: CriticalAction::OPERATIONAL_AUTHORIZE,
                resourceType: $operation->resourceType,
                resourceId: $operation->resourceId,
                branchId: $operation->branchId,
                parameters: $operation->parameters,
                reason: $operation->reason,
            ));
            $plainToken = bin2hex(random_bytes(32));
            $expiresAt = now()->addMinutes($this->ttlMinutes());
            OperationalAuthorizationToken::query()->create([
                'requester_user_id' => $requester->id,
                'authorizer_user_id' => $authorizer->id,
                'executor_user_id' => $executor->id,
                'authorizer_session_id' => $authorizerSession->id,
                'action' => $operation->action->value,
                'resource_type' => $operation->resourceType,
                'resource_id' => $operation->resourceId,
                'branch_id' => $operation->branchId,
                'parameters_hash' => $operation->parametersHash(),
                'reason' => $operation->reason,
                'token_hash' => hash('sha256', $plainToken),
                'context_version' => $authorizer->context_version,
                'issued_at' => now(),
                'expires_at' => $expiresAt,
            ]);
            $this->recorder->audit('OPERATIONAL_AUTHORIZATION_ISSUED', 'AUTHORIZED', $authorizer, $requester, [
                'requester_user_id' => $requester->id,
                'authorizer_user_id' => $authorizer->id,
                'executor_user_id' => $executor->id,
                'action' => $operation->action->value,
                'resource_type' => $operation->resourceType,
                'resource_id' => $operation->resourceId,
                'branch_id' => $operation->branchId,
                'reason' => $operation->reason,
            ]);

            return [
                'operational_token' => $plainToken,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }

    /**
     * Must be called in the same transaction as the protected operation.
     */
    public function consume(
        User $executor,
        #[SensitiveParameter] string $plainToken,
        AuthorizationBinding $operation,
    ): OperationalAuthorizationToken {
        $authorization = OperationalAuthorizationToken::query()
            ->where('executor_user_id', $executor->id)
            ->where('action', $operation->action->value)
            ->where('resource_type', $operation->resourceType)
            ->where('resource_id', $operation->resourceId)
            ->where('branch_id', $operation->branchId)
            ->where('parameters_hash', $operation->parametersHash())
            ->where('reason', $operation->reason)
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();

        if ($authorization === null) {
            throw new AccessRuleViolation(
                'La autorización operativa no corresponde a esta ejecución.',
                403,
                'OPERATIONAL_AUTHORIZATION_REQUIRED',
            );
        }

        $authorization->forceFill(['used_at' => now()])->save();

        return $authorization;
    }

    private function ttlMinutes(): int
    {
        return max(1, min(5, (int) config('access.tokens.operational_ttl_minutes', 5)));
    }
}
