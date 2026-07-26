<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Services;

use App\Modules\ExcessBalance\Application\DTOs\OperationContext;
use App\Modules\ExcessBalance\Application\Security\ExcessScopeService;
use App\Modules\ExcessBalance\Domain\Enums\ExcessBalanceBucket;
use App\Modules\ExcessBalance\Domain\Enums\ExcessLedgerEntryType;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use App\Modules\ExcessBalance\Domain\Services\ExcessBalanceInvariant;
use App\Modules\ExcessBalance\Domain\Services\ExcessStateMachine;
use App\Modules\ExcessBalance\Domain\ValueObjects\Money;
use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use App\Modules\Payment\Domain\Enums\RefundRequestStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RequestExcessRefund
{
    public function __construct(
        private ExcessScopeService $scope,
        private ExcessIdempotencyService $idempotency,
        private ExcessBalanceInvariant $invariant,
        private ExcessStateMachine $states,
        private ExcessRecorder $recorder,
    ) {}

    /** @return array<string, mixed> */
    public function execute(
        string $id,
        int $expectedVersion,
        ?string $reason,
        OperationContext $context,
    ): array {
        return DB::transaction(function () use ($id, $expectedVersion, $reason, $context): array {
            $reservation = $this->idempotency->reserve(
                $context->actor->id,
                'REQUEST_EXCESS_REFUND',
                $id,
                $context->idempotencyKey,
                ['lock_version' => $expectedVersion, 'reason' => $reason],
            );
            $replay = $this->idempotency->replay($reservation);
            if ($replay !== null) {
                return $replay;
            }

            $balance = ExcessBalanceModel::query()->whereKey($id)->lockForUpdate()->first()
                ?? throw ExcessBalanceException::notFound();
            $this->scope->assertOwner($context->actor, (int) $balance->distributor_id);
            if ((int) $balance->lock_version !== $expectedVersion
                || $balance->status !== ExcessBalanceStatus::PENDING_DECISION
                || RefundRequestModel::query()->where('excess_balance_id', $balance->id)->exists()) {
                throw ExcessBalanceException::stateConflict();
            }

            $this->normalizeLegacyPendingBalance($balance);
            $amount = new Money((string) $balance->retained_amount);
            if (! $amount->isPositive()) {
                throw ExcessBalanceException::stateConflict();
            }
            $this->assertInvariant($balance);
            $before = $this->recorder->amounts($balance);
            $previousStatus = $balance->status->value;
            $request = RefundRequestModel::query()->create([
                'request_number' => $this->folio(),
                'excess_balance_id' => $balance->id,
                'distributor_id' => $balance->distributor_id,
                'branch_id' => $balance->branch_id,
                'amount' => $amount->value(),
                'status' => RefundRequestStatus::PENDING_AUTHORIZATION,
                'requested_by' => $context->actor->id,
                'request_reason' => $reason,
                'requested_at' => now('UTC'),
                'lock_version' => 1,
            ]);
            $this->states->assertTransition($balance->status, ExcessBalanceStatus::REFUND_PENDING);
            $balance->forceFill([
                'retained_amount' => '0.0000',
                'reserved_refund_amount' => $amount->value(),
                'status' => ExcessBalanceStatus::REFUND_PENDING,
                'decision' => ExcessBalanceStatus::REFUND_PENDING->value,
                'decided_by' => $context->actor->id,
                'decided_at' => now('UTC'),
                'lock_version' => $balance->lock_version + 1,
            ])->save();
            $this->assertInvariant($balance);
            $after = $this->recorder->amounts($balance);
            $operationKey = 'refund-request:'.$request->id.':'.$context->idempotencyKey;
            $this->recorder->ledger(
                $balance->id,
                ExcessLedgerEntryType::RESERVED_FOR_REFUND,
                $amount->value(),
                ExcessBalanceBucket::RETAINED,
                ExcessBalanceBucket::RESERVED,
                $operationKey,
                $context->actor->id,
                refundId: $request->id,
            );
            $this->recorder->history(
                $balance,
                $previousStatus,
                $balance->status->value,
                $before,
                $after,
                $operationKey,
                $context->actor->id,
                $reason,
                $request->id,
            );
            $this->recorder->audit(
                'REFUND_REQUESTED',
                'SUCCESS',
                'refund_requests',
                $request->id,
                $context,
                $before,
                $after,
                ['excess_balance_id' => $balance->id],
                $reason,
            );
            $this->recorder->event('RefundRequested', $balance, [
                'refund_request_id' => $request->id,
                'amount' => $amount->value(),
                'correlation_id' => $context->correlationId,
            ], $operationKey);
            $response = [
                'id' => $request->id,
                'request_number' => $request->request_number,
                'excess_balance_id' => $balance->id,
                'status' => $request->status->value,
                'requested_amount' => (string) $request->amount,
                'lock_version' => (int) $request->lock_version,
            ];
            $this->idempotency->complete((string) $reservation->id, 201, $response);

            return $response;
        }, 3);
    }

    private function normalizeLegacyPendingBalance(ExcessBalanceModel $balance): void
    {
        $retained = new Money((string) $balance->retained_amount);
        $available = new Money((string) $balance->available_amount);
        if (! $retained->isPositive() && $available->isPositive()) {
            $balance->forceFill([
                'retained_amount' => $available->value(),
                'available_amount' => '0.0000',
            ]);
        }
    }

    private function assertInvariant(ExcessBalanceModel $balance): void
    {
        $this->invariant->assert(
            new Money((string) $balance->original_amount),
            new Money((string) $balance->retained_amount),
            new Money((string) $balance->available_amount),
            new Money((string) $balance->reserved_refund_amount),
            new Money((string) $balance->applied_amount),
            new Money((string) $balance->refunded_amount),
        );
    }

    private function folio(): string
    {
        return 'REF-'.Str::upper(Str::random(16));
    }
}
