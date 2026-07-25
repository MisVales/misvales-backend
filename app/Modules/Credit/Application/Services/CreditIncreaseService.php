<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Services;

use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\DistributorAccessLink;
use App\Modules\Credit\Domain\Aggregates\CreditLine;
use App\Modules\Credit\Domain\Enums\CreditMovementType;
use App\Modules\Credit\Domain\Enums\IncreaseOriginType;
use App\Modules\Credit\Domain\Enums\IncreaseRequestStatus;
use App\Modules\Credit\Domain\Enums\RestrictionTriggerType;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Domain\Repositories\CreditLineRepository;
use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Mappers\CreditLineMapper;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditIncreaseRequestModel;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineMovementModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreditIncreaseService
{
    public function __construct(
        private CreditLineRepository $lines,
        private CreditLineMapper $mapper,
        private CreditScopeService $scope,
        private CreditMovementService $movements,
        private CreditRestrictionService $restrictions,
        private CreditRecorder $recorder,
        private TemporaryAuthorization $reauthentication,
    ) {}

    public function request(
        User $actor,
        User $distributor,
        Money $requestedAmount,
        string $reason,
        IncreaseOriginType $origin,
        ?Money $productAmount,
        string $idempotencyKey,
    ): CreditIncreaseRequestModel {
        $this->scope->assertCanRequest($actor, $distributor);

        return DB::transaction(function () use ($actor, $distributor, $requestedAmount, $reason, $origin, $productAmount, $idempotencyKey): CreditIncreaseRequestModel {
            $line = $this->lines->lockForDistributor($distributor->id);
            if ($line === null) {
                throw new CreditRuleViolation('No existe línea para la distribuidora.', 'CREDIT_LINE_NOT_FOUND', 404);
            }
            $existing = CreditIncreaseRequestModel::query()
                ->where('distributor_id', $distributor->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->recorder->audit(
                    'CREDIT_DUPLICATE_ATTEMPT',
                    'IDEMPOTENT_REPLAY',
                    $actor,
                    $distributor->id,
                    $distributor->branch_id,
                    'credit_increase_requests',
                    $existing->public_id,
                    reason: $reason,
                    metadata: ['operation' => 'REQUEST_INCREASE'],
                    idempotencyKey: $idempotencyKey,
                );

                return $existing;
            }
            if (! $requestedAmount->isPositive()) {
                throw new CreditRuleViolation('El importe solicitado debe ser mayor que cero.', 'CREDIT_INCREASE_AMOUNT_INVALID', 422);
            }
            if ($distributor->state !== AccountState::ACTIVE) {
                throw new CreditRuleViolation('La distribuidora no se encuentra activa.', 'AUTH_SCOPE_DENIED', 403);
            }
            $link = DistributorAccessLink::query()->where('user_id', $distributor->id)->lockForUpdate()->first();
            if ($link === null || $link->branch_id !== $distributor->branch_id) {
                throw new CreditRuleViolation('La asignación de la distribuidora no es válida.', 'AUTH_SCOPE_DENIED', 403);
            }

            $available = new Money($line->available_balance);
            $requiredDifference = null;
            if ($origin === IncreaseOriginType::INSUFFICIENT_CREDIT) {
                if ($productAmount === null || ! $productAmount->greaterThan($available)) {
                    throw new CreditRuleViolation(
                        'El origen por crédito insuficiente requiere un producto mayor al saldo actual.',
                        'CREDIT_INCREASE_AMOUNT_INVALID',
                        422,
                    );
                }
                $requiredDifference = $productAmount->subtract($available);
            }

            $request = CreditIncreaseRequestModel::query()->create([
                'folio' => $this->nextFolio(),
                'distributor_id' => $distributor->id,
                'credit_line_id' => $line->id,
                'branch_id' => $link->branch_id,
                'coordinator_id' => $link->coordinator_user_id,
                'requested_amount' => $requestedAmount->databaseValue(),
                'origin_type' => $origin,
                'product_amount' => $productAmount?->databaseValue(),
                'available_balance_snapshot' => $line->available_balance,
                'required_difference' => $requiredDifference?->databaseValue(),
                'total_authorized_snapshot' => $line->total_authorized,
                'used_balance_snapshot' => $line->used_balance,
                'credit_line_version_snapshot' => $line->lock_version,
                'status' => IncreaseRequestStatus::REQUESTED,
                'request_reason' => $reason,
                'requested_by_user_id' => $actor->id,
                'requested_at' => now('UTC'),
                'lock_version' => 1,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->recorder->audit(
                'CREDIT_INCREASE_REQUESTED',
                'SUCCESS',
                $actor,
                $distributor->id,
                $link->branch_id,
                'credit_increase_requests',
                $request->public_id,
                null,
                $this->requestSnapshot($request),
                $reason,
                ['required_difference' => $requiredDifference?->format(), 'origin' => $origin->value],
                $idempotencyKey,
            );
            $this->recorder->event('CreditIncreaseRequested', "credit-increase-requested:{$request->public_id}", [
                'request_id' => $request->public_id,
                'folio' => $request->folio,
                'distributor_id' => $distributor->public_id,
                'branch_id' => $link->branch_id,
                'coordinator_id' => $link->coordinator_user_id,
                'actor_id' => $actor->public_id,
                'amount' => $requestedAmount->format(),
                'reason' => $reason,
                'occurred_at' => now('UTC')->toIso8601String(),
            ]);

            return $request;
        }, 3);
    }

    public function review(
        User $actor,
        CreditIncreaseRequestModel $increaseRequest,
        string $decision,
        ?Money $recommendedAmount,
        string $reason,
        ?int $expectedVersion = null,
    ): CreditIncreaseRequestModel {
        return DB::transaction(function () use ($actor, $increaseRequest, $decision, $recommendedAmount, $reason, $expectedVersion): CreditIncreaseRequestModel {
            $request = CreditIncreaseRequestModel::query()->lockForUpdate()->findOrFail($increaseRequest->id);
            $this->scope->assertCanReview($actor, $request);
            if ($expectedVersion !== null && $expectedVersion !== $request->lock_version) {
                throw new CreditRuleViolation('La solicitud cambió desde que fue consultada.', 'RESOURCE_VERSION_CONFLICT');
            }
            if ($request->status !== IncreaseRequestStatus::REQUESTED) {
                if (($decision === 'PREAUTHORIZE' && $request->status === IncreaseRequestStatus::PREAUTHORIZED)
                    || ($decision === 'REJECT' && $request->status === IncreaseRequestStatus::REJECTED_BY_COORDINATOR)) {
                    return $request;
                }
                throw new CreditRuleViolation('La solicitud ya no admite revisión.', 'CREDIT_INCREASE_INVALID_STATE');
            }

            $before = $this->requestSnapshot($request);
            if ($decision === 'PREAUTHORIZE') {
                if ($recommendedAmount === null || ! $recommendedAmount->isPositive()
                    || $recommendedAmount->greaterThan(new Money($request->requested_amount))) {
                    throw new CreditRuleViolation('El importe recomendado no es válido.', 'CREDIT_INCREASE_AMOUNT_INVALID', 422);
                }
                $request->recommended_amount = $recommendedAmount->databaseValue();
                $request->status = IncreaseRequestStatus::PREAUTHORIZED;
            } elseif ($decision === 'REJECT') {
                $request->status = IncreaseRequestStatus::REJECTED_BY_COORDINATOR;
            } else {
                throw new CreditRuleViolation('La decisión del coordinador no es válida.', 'CREDIT_INCREASE_INVALID_STATE', 422);
            }
            $request->coordinator_reason = $reason;
            $request->reviewed_by_user_id = $actor->id;
            $request->reviewed_at = now('UTC')->toImmutable();
            $request->lock_version++;
            $request->save();

            $eventType = $decision === 'PREAUTHORIZE' ? 'CreditIncreasePreauthorized' : 'CreditIncreaseRejected';
            $this->recorder->audit(
                $decision === 'PREAUTHORIZE' ? 'CREDIT_INCREASE_PREAUTHORIZED' : 'CREDIT_INCREASE_REJECTED_BY_COORDINATOR',
                'SUCCESS',
                $actor,
                $request->distributor_id,
                $request->branch_id,
                'credit_increase_requests',
                $request->public_id,
                $before,
                $this->requestSnapshot($request),
                $reason,
                ['recommended_amount' => $recommendedAmount?->format()],
                reviewerId: $actor->id,
            );
            $this->recorder->event($eventType, "credit-increase-review:{$request->public_id}", [
                'request_id' => $request->public_id,
                'folio' => $request->folio,
                'distributor_id' => $request->distributor_id,
                'branch_id' => $request->branch_id,
                'actor_id' => $actor->public_id,
                'amount' => $recommendedAmount?->format(),
                'reason' => $reason,
                'status' => $request->status->value,
                'occurred_at' => now('UTC')->toIso8601String(),
            ]);

            return $request->refresh();
        }, 3);
    }

    public function managerDecision(
        User $actor,
        CreditIncreaseRequestModel $increaseRequest,
        string $decision,
        ?Money $authorizedAmount,
        string $reason,
        string $reauthenticationToken,
        ?int $expectedVersion = null,
    ): CreditIncreaseRequestModel {
        return DB::transaction(function () use ($actor, $increaseRequest, $decision, $authorizedAmount, $reason, $reauthenticationToken, $expectedVersion): CreditIncreaseRequestModel {
            $request = CreditIncreaseRequestModel::query()->lockForUpdate()->findOrFail($increaseRequest->id);
            $this->scope->assertCanDecide($actor, $request);
            if ($this->sameManagerDecision($request, $decision, $authorizedAmount)) {
                $this->recorder->audit(
                    'CREDIT_DUPLICATE_ATTEMPT',
                    'IDEMPOTENT_REPLAY',
                    $actor,
                    $request->distributor_id,
                    $request->branch_id,
                    'credit_increase_requests',
                    $request->public_id,
                    reason: $reason,
                    metadata: ['operation' => 'MANAGER_DECISION'],
                    authorizerId: $actor->id,
                );

                return $request;
            }
            if ($expectedVersion !== null && $expectedVersion !== $request->lock_version) {
                throw new CreditRuleViolation('La solicitud cambió desde que fue consultada.', 'RESOURCE_VERSION_CONFLICT');
            }
            if ($request->status !== IncreaseRequestStatus::PREAUTHORIZED) {
                throw new CreditRuleViolation('La solicitud no está preautorizada.', 'CREDIT_INCREASE_NOT_PREAUTHORIZED');
            }

            $line = $this->lines->lockForDistributor($request->distributor_id);
            if ($line === null) {
                throw new CreditRuleViolation('No existe línea para la distribuidora.', 'CREDIT_LINE_NOT_FOUND', 404);
            }
            if ($line->lock_version !== $request->credit_line_version_snapshot) {
                throw new CreditRuleViolation('La línea cambió desde que se creó la solicitud.', 'RESOURCE_VERSION_CONFLICT');
            }
            $distributor = User::query()->lockForUpdate()->find($request->distributor_id);
            if ($distributor === null || $distributor->state !== AccountState::ACTIVE) {
                throw new CreditRuleViolation('La distribuidora ya no se encuentra activa.', 'AUTH_SCOPE_DENIED', 403);
            }

            $branchPublicId = Branch::query()->whereKey($request->branch_id)->value('public_id');
            $parameters = [
                'decision' => $decision,
                'authorized_amount' => $authorizedAmount?->format(),
            ];
            $this->reauthentication->consume($actor, $reauthenticationToken, new AuthorizationBinding(
                action: CriticalAction::CREDIT_INCREASE_DECISION,
                resourceType: 'credit_increase_requests',
                resourceId: $request->public_id,
                branchId: is_string($branchPublicId) ? $branchPublicId : null,
                parameters: $parameters,
            ));

            $beforeRequest = $this->requestSnapshot($request);
            $beforeLine = $this->mapper->toDomain($line);
            if ($decision === 'REJECT') {
                $request->forceFill([
                    'status' => IncreaseRequestStatus::REJECTED_BY_MANAGER,
                    'manager_reason' => $reason,
                    'decided_by_user_id' => $actor->id,
                    'decided_at' => now('UTC'),
                    'lock_version' => $request->lock_version + 1,
                ])->save();
                $this->recordManagerDecision($actor, $request, $beforeRequest, $beforeLine, $beforeLine, $reason, null, null);

                return $request->refresh();
            }
            if ($decision !== 'AUTHORIZE' || $authorizedAmount === null || ! $authorizedAmount->isPositive()) {
                throw new CreditRuleViolation('El importe autorizado no es válido.', 'CREDIT_INCREASE_AMOUNT_INVALID', 422);
            }
            $requested = new Money($request->requested_amount);
            if ($authorizedAmount->greaterThan($requested)) {
                throw new CreditRuleViolation('No se puede autorizar más de lo solicitado.', 'CREDIT_INCREASE_EXCEEDS_REQUESTED', 422);
            }

            $movementKey = "credit-increase:{$request->public_id}";
            $duplicate = CreditLineMovementModel::query()->where('idempotency_key', $movementKey)->first();
            if ($duplicate !== null) {
                return $request->refresh();
            }
            $afterLine = $beforeLine->increase($authorizedAmount);
            $movement = $this->movements->append(
                $line,
                $beforeLine,
                $afterLine,
                CreditMovementType::INCREASE,
                'CREDIT_INCREASE_REQUEST',
                $request->public_id,
                $actor,
                $actor->id,
                $request->branch_id,
                $reason,
                $movementKey,
                [
                    'requested_amount' => $requested->format(),
                    'recommended_amount' => (new Money($request->recommended_amount))->format(),
                    'authorized_amount' => $authorizedAmount->format(),
                    'percentage' => (string) config('credit.percentage'),
                    'tolerance' => (string) config('credit.fifty_percent_tolerance'),
                ],
            );
            $restriction = $this->restrictions->create(
                $line->refresh(),
                RestrictionTriggerType::INCREASE,
                $request->public_id,
            );
            $authorizationStatus = $authorizedAmount->equals($requested)
                ? IncreaseRequestStatus::FULLY_AUTHORIZED
                : IncreaseRequestStatus::PARTIALLY_AUTHORIZED;
            $request->forceFill([
                'authorized_amount' => $authorizedAmount->databaseValue(),
                'status' => $authorizationStatus,
                'manager_reason' => $reason,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now('UTC'),
                'restriction_id' => $restriction->id,
                'lock_version' => $request->lock_version + 1,
            ])->save();
            // The financial change and restriction are complete; the process now waits for its first voucher.
            $request->forceFill([
                'status' => IncreaseRequestStatus::FIFTY_PERCENT_RESTRICTION_ACTIVE,
                'lock_version' => $request->lock_version + 1,
            ])->save();
            $this->recordManagerDecision($actor, $request, $beforeRequest, $beforeLine, $afterLine, $reason, $movement->public_id, $restriction->public_id, $authorizationStatus);

            return $request->refresh();
        }, 3);
    }

    private function nextFolio(): string
    {
        return 'INC-'.now((string) config('credit.display_timezone'))->format('Ymd').'-'.strtoupper(Str::random(10));
    }

    private function sameManagerDecision(CreditIncreaseRequestModel $request, string $decision, ?Money $amount): bool
    {
        if ($decision === 'REJECT') {
            return $request->status === IncreaseRequestStatus::REJECTED_BY_MANAGER;
        }

        return $decision === 'AUTHORIZE'
            && in_array($request->status, [
                IncreaseRequestStatus::FULLY_AUTHORIZED,
                IncreaseRequestStatus::PARTIALLY_AUTHORIZED,
                IncreaseRequestStatus::FIFTY_PERCENT_RESTRICTION_ACTIVE,
                IncreaseRequestStatus::COMPLETED,
            ], true)
            && $amount !== null
            && $request->authorized_amount !== null
            && $amount->equals(new Money($request->authorized_amount));
    }

    /** @param array<string, mixed> $beforeRequest */
    private function recordManagerDecision(
        User $actor,
        CreditIncreaseRequestModel $request,
        array $beforeRequest,
        CreditLine $beforeLine,
        CreditLine $afterLine,
        string $reason,
        ?string $movementId,
        ?string $restrictionId,
        ?IncreaseRequestStatus $authorizationStatus = null,
    ): void {
        $authorized = $request->authorized_amount === null ? null : new Money($request->authorized_amount);
        $eventType = match ($authorizationStatus) {
            IncreaseRequestStatus::FULLY_AUTHORIZED => 'CreditIncreaseFullyAuthorized',
            IncreaseRequestStatus::PARTIALLY_AUTHORIZED => 'CreditIncreasePartiallyAuthorized',
            default => 'CreditIncreaseRejected',
        };
        $this->recorder->audit(
            $authorizationStatus === null ? 'CREDIT_INCREASE_REJECTED_BY_MANAGER' : 'CREDIT_INCREASE_AUTHORIZED',
            'SUCCESS',
            $actor,
            $request->distributor_id,
            $request->branch_id,
            'credit_increase_requests',
            $request->public_id,
            ['request' => $beforeRequest, 'line' => $this->lineSnapshot($beforeLine)],
            ['request' => $this->requestSnapshot($request), 'line' => $this->lineSnapshot($afterLine)],
            $reason,
            ['movement_id' => $movementId, 'restriction_id' => $restrictionId],
            authorizerId: $actor->id,
        );
        $payload = [
            'request_id' => $request->public_id,
            'folio' => $request->folio,
            'distributor_id' => $request->distributor_id,
            'branch_id' => $request->branch_id,
            'actor_id' => $actor->public_id,
            'amount' => $authorized?->format(),
            'movement_id' => $movementId,
            'restriction_id' => $restrictionId,
            'reason' => $reason,
            'balances_before' => $this->lineSnapshot($beforeLine),
            'balances_after' => $this->lineSnapshot($afterLine),
            'occurred_at' => now('UTC')->toIso8601String(),
        ];
        $this->recorder->event($eventType, "credit-increase-decision:{$request->public_id}", $payload);
        if ($restrictionId !== null) {
            $this->recorder->event(
                'PostIncreaseFiftyPercentRestrictionActivated',
                "credit-increase-restriction:{$request->public_id}",
                $payload,
            );
        }
    }

    /** @return array<string, mixed> */
    private function requestSnapshot(CreditIncreaseRequestModel $request): array
    {
        return [
            'status' => $request->status->value,
            'requested_amount' => (new Money($request->requested_amount))->format(),
            'recommended_amount' => $request->recommended_amount === null ? null : (new Money($request->recommended_amount))->format(),
            'authorized_amount' => $request->authorized_amount === null ? null : (new Money($request->authorized_amount))->format(),
            'lock_version' => (int) $request->lock_version,
        ];
    }

    /** @return array<string, string> */
    private function lineSnapshot(CreditLine $line): array
    {
        return [
            'total_authorized' => $line->totalAuthorized->format(),
            'used_balance' => $line->usedBalance->format(),
            'available_balance' => $line->availableBalance()->format(),
            'recovered_capital_total' => $line->recoveredCapitalTotal->format(),
        ];
    }
}
