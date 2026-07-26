<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientChanges;
use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientChangesCommand;
use App\Modules\Client\Application\Contracts\ClientVersionReader;
use App\Modules\Client\Application\Contracts\CorrectableClientFieldRegistry;
use App\Modules\Client\Application\Security\ClientActorContext;
use App\Modules\Voucher\Application\Contracts\ModificationRequestRepository;
use App\Modules\Voucher\Application\Contracts\VoucherRepository;
use App\Modules\Voucher\Application\DTOs\OperationMetadata;
use App\Modules\Voucher\Application\Security\VoucherActorContext;
use App\Modules\Voucher\Domain\Entities\AuthorizationToken;
use App\Modules\Voucher\Domain\Entities\DataChangeRequest;
use App\Modules\Voucher\Domain\Enums\DataChangeOperation;
use App\Modules\Voucher\Domain\Enums\DataChangeRequestStatus;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Domain\Services\ModificationTokenService;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\AuthorizationTokenModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\DataChangeRequestModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherChangeHistoryModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Flujo completo de discrepancias y correcciones autorizadas. */
final readonly class ModificationWorkflowService
{
    public function __construct(
        private VoucherRepository $vouchers,
        private ModificationRequestRepository $requests,
        private CorrectableClientFieldRegistry $fields,
        private ClientVersionReader $clientVersions,
        private ApplyAuthorizedClientChanges $clientChanges,
        private TemporaryAuthorization $reauthentication,
        private ModificationTokenService $tokens,
        private VerifiedModificationContext $verified,
        private IdempotencyService $idempotency,
        private VoucherRecorder $recorder,
        private SensitiveReasonGuard $reasons,
    ) {}

    /** @param list<string> $fields
     * @return array<string, mixed>
     */
    public function request(
        string $voucherId,
        array $fields,
        string $reason,
        int $expectedVersion,
        VoucherActorContext $actor,
        OperationMetadata $metadata,
    ): array {
        $this->assertCashier($actor, PermissionCode::VOUCHER_MODIFICATIONS_REQUEST);
        $fields = array_values(array_unique($fields));
        sort($fields);
        if (! $this->fields->containsExactly($fields)) {
            throw VoucherDomainException::fieldNotAllowed();
        }
        $reason = $this->reasons->sanitize($reason);

        try {
            return DB::transaction(function () use (
                $voucherId,
                $fields,
                $reason,
                $expectedVersion,
                $actor,
                $metadata,
            ): array {
                $reservation = $this->idempotency->reserve(
                    $actor->userId,
                    'REQUEST_MODIFICATION',
                    $metadata->idempotencyKey,
                    [
                        'voucher_id' => $voucherId,
                        'fields' => $fields,
                        'reason' => $reason,
                        'lock_version' => $expectedVersion,
                    ],
                );
                if ($reservation->completed_at !== null) {
                    return $reservation->response_payload ?? [];
                }
                $voucher = $this->vouchers->lockScoped($voucherId, $actor);
                $this->assertVersion($voucher->lock_version, $expectedVersion);
                if ($this->requests->hasActiveForVoucher($voucher->id)) {
                    throw VoucherDomainException::modificationActive();
                }
                $aggregate = $voucher->toAggregate();
                $before = $voucher->status;
                $aggregate->requestCorrection();
                $request = new DataChangeRequestModel;
                $request->forceFill([
                    'voucher_id' => $voucher->id,
                    'client_id' => $voucher->client_id,
                    'branch_id' => $voucher->branch_id,
                    'requested_by' => $actor->userId,
                    'operation' => DataChangeOperation::CLIENT_DATA_CORRECTION,
                    'authorized_fields' => $fields,
                    'reason' => $reason,
                    'status' => DataChangeRequestStatus::PENDING,
                    'requested_at' => now('UTC'),
                    'target_lock_versions' => [
                        'client' => $this->clientVersions->lockVersion($voucher->client_id),
                        'voucher' => $expectedVersion,
                    ],
                ])->save();
                $voucher->forceFill([
                    'status' => $aggregate->status(),
                    'lock_version' => $aggregate->lockVersion(),
                ])->save();
                $this->recorder->operation(
                    $voucher->id,
                    'VOUCHER_MODIFICATION_REQUESTED',
                    $before->value,
                    $voucher->status->value,
                    $actor,
                    $metadata,
                    ['request_id' => $request->id, 'fields' => $fields],
                );
                $this->recorder->event(
                    'VoucherModificationRequested',
                    $voucher->id,
                    'voucher-modification-requested:'.$request->id,
                    [
                        'request_id' => $request->id,
                        'voucher_id' => $voucher->id,
                        'fields' => $fields,
                        'branch_id' => $actor->branchPublicId,
                        'requested_at' => $request->requested_at->toIso8601String(),
                    ],
                );
                $response = $this->requestData($request);
                $this->idempotency->complete($reservation, 201, $response);

                return $response;
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'active_voucher')) {
                throw VoucherDomainException::modificationActive();
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DataChangeRequestModel>
     */
    public function list(VoucherActorContext $actor, array $filters): LengthAwarePaginator
    {
        $this->assertCanReview($actor);

        return $this->requests->list($actor, $filters);
    }

    /** @return array<string, mixed> */
    public function get(string $id, VoucherActorContext $actor): array
    {
        $this->assertCanReview($actor);

        return $this->requestData($this->requests->findScoped($id, $actor));
    }

    /** @return array<string, mixed> */
    public function authorize(
        string $id,
        int $expectedVersion,
        string $decisionReason,
        string $reauthenticationToken,
        User $user,
        VoucherActorContext $actor,
        OperationMetadata $metadata,
    ): array {
        $this->assertAuthority($actor);
        $decisionReason = $this->reasons->sanitize($decisionReason, 'decision_reason');

        return DB::transaction(function () use (
            $id,
            $expectedVersion,
            $decisionReason,
            $reauthenticationToken,
            $user,
            $actor,
            $metadata,
        ): array {
            $reservation = $this->idempotency->reserve($actor->userId, 'AUTHORIZE_MODIFICATION', $metadata->idempotencyKey, [
                'request_id' => $id,
                'lock_version' => $expectedVersion,
                'decision_reason' => $decisionReason,
            ]);
            if ($reservation->completed_at !== null) {
                return $reservation->response_payload ?? [];
            }
            $request = $this->requests->lockScoped($id, $actor);
            $this->assertVersion($request->lock_version, $expectedVersion);
            $this->assertAuthorityScope($request, $actor);
            if ($request->requested_by === $actor->userId) {
                throw VoucherDomainException::scopeDenied();
            }
            $voucher = $this->vouchers->lockScoped($request->voucher_id, $actor);
            if ($voucher->status !== VoucherStatus::CORRECTION_PENDING) {
                throw VoucherDomainException::invalidTransition();
            }
            if (! $this->fields->containsExactly($request->authorized_fields)) {
                throw VoucherDomainException::fieldNotAllowed();
            }
            $this->reauthentication->consume($user, $reauthenticationToken, $this->decisionBinding(
                $request,
                $decisionReason,
            ));
            $entity = new DataChangeRequest($request->status, $request->lock_version);
            $entity->authorize();
            $issued = $this->tokens->issue();
            $issuedAt = now('UTC')->toImmutable();
            $expiresAt = $issuedAt->addSeconds($this->tokens->ttlSeconds());
            $token = new AuthorizationTokenModel;
            $token->forceFill([
                'data_change_request_id' => $request->id,
                'token_hash' => $issued['hash'],
                'cashier_id' => $request->requested_by,
                'voucher_id' => $request->voucher_id,
                'client_id' => $request->client_id,
                'branch_id' => $request->branch_id,
                'operation' => $request->operation,
                'field_scope' => $request->authorized_fields,
                'issued_by' => $actor->userId,
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
            ])->save();
            $request->forceFill([
                'status' => $entity->status(),
                'lock_version' => $entity->lockVersion(),
                'decided_by' => $actor->userId,
                'decision_reason' => $decisionReason,
                'decided_at' => $issuedAt,
            ])->save();
            $this->recorder->operation(
                $voucher->id,
                'VOUCHER_MODIFICATION_AUTHORIZED',
                $voucher->status->value,
                $voucher->status->value,
                $actor,
                $metadata,
                ['request_id' => $request->id, 'expires_at' => $expiresAt->toIso8601String()],
            );
            $this->recorder->event(
                'VoucherModificationAuthorized',
                $voucher->id,
                'voucher-modification-authorized:'.$request->id,
                [
                    'request_id' => $request->id,
                    'authority_id' => $actor->publicId,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            );
            $storedResponse = [
                ...$this->requestData($request),
                'expires_at' => $expiresAt->toIso8601String(),
            ];
            $this->idempotency->complete($reservation, 200, $storedResponse);

            return [...$storedResponse, 'token' => $issued['plain']];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function reject(
        string $id,
        int $expectedVersion,
        string $decisionReason,
        string $reauthenticationToken,
        User $user,
        VoucherActorContext $actor,
        OperationMetadata $metadata,
    ): array {
        $this->assertAuthority($actor);
        $decisionReason = $this->reasons->sanitize($decisionReason, 'decision_reason');

        return DB::transaction(function () use (
            $id,
            $expectedVersion,
            $decisionReason,
            $reauthenticationToken,
            $user,
            $actor,
            $metadata,
        ): array {
            $reservation = $this->idempotency->reserve($actor->userId, 'REJECT_MODIFICATION', $metadata->idempotencyKey, [
                'request_id' => $id,
                'lock_version' => $expectedVersion,
                'decision_reason' => $decisionReason,
            ]);
            if ($reservation->completed_at !== null) {
                return $reservation->response_payload ?? [];
            }
            $request = $this->requests->lockScoped($id, $actor);
            $this->assertVersion($request->lock_version, $expectedVersion);
            $this->assertAuthorityScope($request, $actor);
            if ($request->requested_by === $actor->userId) {
                throw VoucherDomainException::scopeDenied();
            }
            $voucher = $this->vouchers->lockScoped($request->voucher_id, $actor);
            $this->reauthentication->consume($user, $reauthenticationToken, $this->decisionBinding(
                $request,
                $decisionReason,
            ));
            $entity = new DataChangeRequest($request->status, $request->lock_version);
            $entity->reject();
            $request->forceFill([
                'status' => $entity->status(),
                'lock_version' => $entity->lockVersion(),
                'decided_by' => $actor->userId,
                'decision_reason' => $decisionReason,
                'decided_at' => now('UTC'),
            ])->save();
            $this->recorder->operation(
                $voucher->id,
                'VOUCHER_MODIFICATION_REJECTED',
                $voucher->status->value,
                $voucher->status->value,
                $actor,
                $metadata,
                ['request_id' => $request->id],
            );
            $this->recorder->event(
                'VoucherModificationRejected',
                $voucher->id,
                'voucher-modification-rejected:'.$request->id,
                [
                    'request_id' => $request->id,
                    'authority_id' => $actor->publicId,
                    'decision_reason' => $decisionReason,
                    'rejected_at' => now('UTC')->toIso8601String(),
                ],
            );
            $response = $this->requestData($request);
            $this->idempotency->complete($reservation, 200, $response);

            return $response;
        }, 3);
    }

    /** @param array<string, array<string, mixed>> $changes
     * @return array<string, mixed>
     */
    public function apply(
        string $id,
        string $plainToken,
        array $changes,
        int $expectedVoucherVersion,
        VoucherActorContext $actor,
        OperationMetadata $metadata,
    ): array {
        $this->assertCashier($actor, PermissionCode::VOUCHER_MODIFICATIONS_APPLY);
        $result = DB::transaction(function () use (
            $id,
            $plainToken,
            $changes,
            $expectedVoucherVersion,
            $actor,
            $metadata,
        ): array {
            $reservation = $this->idempotency->reserve($actor->userId, 'APPLY_MODIFICATION', $metadata->idempotencyKey, [
                'request_id' => $id,
                'token_hash' => hash('sha256', $plainToken),
                'changes' => $changes,
                'lock_version' => $expectedVoucherVersion,
            ]);
            if ($reservation->completed_at !== null) {
                return $reservation->response_payload ?? [];
            }
            $request = $this->requests->lockScoped($id, $actor);
            if ($request->status === DataChangeRequestStatus::USED) {
                throw VoucherDomainException::tokenUsed();
            }
            if ($request->status === DataChangeRequestStatus::EXPIRED) {
                throw VoucherDomainException::tokenExpired();
            }
            if ($request->status !== DataChangeRequestStatus::AUTHORIZED) {
                throw VoucherDomainException::tokenInvalid();
            }
            if ($request->requested_by !== $actor->userId) {
                throw VoucherDomainException::tokenInvalid();
            }
            $voucher = $this->vouchers->lockScoped($request->voucher_id, $actor);
            $this->assertVersion($voucher->lock_version, $expectedVoucherVersion);
            if ($voucher->status !== VoucherStatus::CORRECTION_PENDING) {
                throw VoucherDomainException::invalidTransition();
            }
            $token = AuthorizationTokenModel::query()
                ->where('data_change_request_id', $request->id)
                ->lockForUpdate()
                ->first();
            if ($token === null) {
                throw VoucherDomainException::tokenInvalid();
            }
            if (now('UTC')->gte($token->expires_at)) {
                $request->forceFill([
                    'status' => DataChangeRequestStatus::EXPIRED,
                    'expired_at' => now('UTC'),
                    'lock_version' => $request->lock_version + 1,
                ])->save();
                $this->recorder->event(
                    'VoucherModificationExpired',
                    $voucher->id,
                    'voucher-modification-expired:'.$request->id,
                    ['request_id' => $request->id, 'expired_at' => now('UTC')->toIso8601String()],
                );

                return ['__expired' => true];
            }
            if (! $this->tokens->matches($token->token_hash, $plainToken)) {
                throw VoucherDomainException::tokenInvalid();
            }
            $fieldNames = array_keys($changes);
            sort($fieldNames);
            $authorizedFields = $request->authorized_fields;
            sort($authorizedFields);
            if ($fieldNames !== $authorizedFields || ! $this->fields->containsExactly($fieldNames)) {
                throw VoucherDomainException::fieldNotAllowed('changes');
            }
            $newValues = [];
            foreach ($fieldNames as $field) {
                if (! array_key_exists('value', $changes[$field]) || count($changes[$field]) !== 1) {
                    throw VoucherDomainException::fieldNotAllowed('changes.'.$field);
                }
                $newValues[$field] = $changes[$field]['value'];
            }
            $tokenScope = new AuthorizationToken(
                cashierId: $token->cashier_id,
                voucherId: $token->voucher_id,
                clientId: $token->client_id,
                branchId: $token->branch_id,
                operation: $token->operation,
                fields: $token->field_scope,
                expiresAt: $token->expires_at,
                consumedAt: $token->consumed_at,
                revokedAt: $token->revoked_at,
            );
            $tokenScope->assertScope(
                $actor->userId,
                $voucher->id,
                $voucher->client_id,
                (int) $voucher->branch_id,
                $request->operation,
                $fieldNames,
                now('UTC')->toImmutable(),
            );
            $this->verified->establish($request->id, $token->id, $request->id);
            $clientResult = $this->clientChanges->handle(new ApplyAuthorizedClientChangesCommand(
                authorizationId: $request->id,
                clientId: $request->client_id,
                authorizedFields: $fieldNames,
                newValues: $newValues,
                reason: $request->reason,
                operationId: $request->id,
                expectedClientVersion: (int) $request->target_lock_versions['client'],
                requestId: $metadata->requestId,
                cashier: $this->clientActor($actor),
            ));
            $token->refresh();
            if ($token->consumed_at === null) {
                throw VoucherDomainException::tokenInvalid();
            }
            $clientHistory = DB::table('client_change_history')
                ->where('operation_id', $request->id)
                ->first();
            if ($clientHistory === null) {
                throw VoucherDomainException::dependencyUnavailable('M06_CHANGE_HISTORY');
            }
            foreach ($fieldNames as $field) {
                $history = new VoucherChangeHistoryModel;
                $history->forceFill([
                    'data_change_request_id' => $request->id,
                    'authorization_token_id' => $token->id,
                    'client_id' => $request->client_id,
                    'record_type' => 'CLIENT',
                    'record_id' => $request->client_id,
                    'field_identifier' => $field,
                    'previous_value_encrypted' => $clientHistory->protected_previous_values,
                    'new_value_encrypted' => $clientHistory->protected_new_values,
                    'executed_by' => $actor->userId,
                    'authorized_by' => $request->decided_by,
                    'branch_id' => $request->branch_id,
                    'request_id' => $metadata->requestId,
                    'changed_at' => now('UTC'),
                ])->save();
            }
            $requestEntity = new DataChangeRequest($request->status, $request->lock_version);
            $requestEntity->use();
            $voucherAggregate = $voucher->toAggregate();
            $before = $voucher->status;
            $voucherAggregate->applyCorrection();
            $request->forceFill([
                'status' => $requestEntity->status(),
                'lock_version' => $requestEntity->lockVersion(),
                'used_at' => now('UTC'),
            ])->save();
            $voucher->forceFill([
                'status' => $voucherAggregate->status(),
                'lock_version' => $voucherAggregate->lockVersion(),
            ])->save();
            $this->recorder->operation(
                $voucher->id,
                'VOUCHER_MODIFICATION_APPLIED',
                $before->value,
                $voucher->status->value,
                $actor,
                $metadata,
                ['request_id' => $request->id, 'fields' => $fieldNames],
            );
            $this->recorder->event(
                'VoucherModificationApplied',
                $voucher->id,
                'voucher-modification-applied:'.$request->id,
                [
                    'request_id' => $request->id,
                    'voucher_id' => $voucher->id,
                    'cashier_id' => $actor->publicId,
                    'fields' => $fieldNames,
                    'applied_at' => now('UTC')->toIso8601String(),
                ],
            );
            $response = [
                ...$this->requestData($request),
                'voucher_status' => $voucher->status->value,
                'voucher_lock_version' => $voucher->lock_version,
                'client_lock_version' => $clientResult->version,
            ];
            $this->idempotency->complete($reservation, 200, $response);

            return $response;
        }, 3);

        if (($result['__expired'] ?? false) === true) {
            throw VoucherDomainException::tokenExpired();
        }

        return $result;
    }

    private function assertCashier(VoucherActorContext $actor, PermissionCode $permission): void
    {
        if (
            $actor->role !== RoleCode::CASHIER
            || $actor->branchId === null
            || ! $actor->hasPermission($permission->value)
        ) {
            throw VoucherDomainException::scopeDenied();
        }
    }

    private function assertAuthority(VoucherActorContext $actor): void
    {
        if (
            ! in_array($actor->role, [
                RoleCode::COORDINATOR,
                RoleCode::SUCURSAL_MANAGER,
                RoleCode::GENERAL_MANAGER,
            ], true)
            || ! $actor->hasPermission(PermissionCode::VOUCHER_MODIFICATIONS_DECIDE->value)
        ) {
            throw VoucherDomainException::scopeDenied();
        }
    }

    private function assertCanReview(VoucherActorContext $actor): void
    {
        if (
            ! in_array($actor->role, [
                RoleCode::COORDINATOR,
                RoleCode::SUCURSAL_MANAGER,
                RoleCode::GENERAL_MANAGER,
                RoleCode::ADMINISTRATOR,
            ], true)
            || ! $actor->hasPermission(PermissionCode::VOUCHER_MODIFICATIONS_VIEW->value)
        ) {
            throw VoucherDomainException::scopeDenied();
        }
    }

    private function assertAuthorityScope(DataChangeRequestModel $request, VoucherActorContext $actor): void
    {
        if ($actor->role !== RoleCode::GENERAL_MANAGER && $actor->branchId !== $request->branch_id) {
            throw VoucherDomainException::scopeDenied();
        }
    }

    private function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw VoucherDomainException::versionConflict();
        }
    }

    private function decisionBinding(DataChangeRequestModel $request, string $reason): AuthorizationBinding
    {
        $branchPublicId = Branch::query()->whereKey($request->branch_id)->value('public_id');

        return new AuthorizationBinding(
            action: CriticalAction::VOUCHER_MODIFICATION_DECIDE,
            resourceType: DataChangeRequestModel::class,
            resourceId: $request->id,
            branchId: is_string($branchPublicId) ? $branchPublicId : null,
            parameters: [
                'voucher_id' => $request->voucher_id,
                'client_id' => $request->client_id,
                'fields' => $request->authorized_fields,
            ],
            reason: $reason,
        );
    }

    /** @return array<string, mixed> */
    private function requestData(DataChangeRequestModel $request): array
    {
        return [
            'request_id' => $request->id,
            'voucher_id' => $request->voucher_id,
            'client_id' => $request->client_id,
            'branch_id' => $request->branch_id,
            'requested_by' => $request->requested_by,
            'operation' => $request->operation->value,
            'fields' => $request->authorized_fields,
            'reason' => $request->reason,
            'status' => $request->status->value,
            'decided_by' => $request->decided_by,
            'decision_reason' => $request->decision_reason,
            'requested_at' => $request->requested_at->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'used_at' => $request->used_at?->toIso8601String(),
            'expired_at' => $request->expired_at?->toIso8601String(),
            'lock_version' => $request->lock_version,
        ];
    }

    private function clientActor(VoucherActorContext $actor): ClientActorContext
    {
        return new ClientActorContext(
            userId: $actor->userId,
            role: $actor->role,
            branchId: $actor->branchId,
            permissions: $actor->permissions,
        );
    }
}
