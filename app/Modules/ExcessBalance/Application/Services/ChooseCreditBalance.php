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
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;
use Illuminate\Support\Facades\DB;

final readonly class ChooseCreditBalance
{
    public function __construct(
        private ExcessScopeService $scope,
        private ExcessIdempotencyService $idempotency,
        private ExcessBalanceInvariant $invariant,
        private ExcessStateMachine $states,
        private ExcessRecorder $recorder,
    ) {}

    /** @return array<string, mixed> */
    public function execute(string $id, int $expectedVersion, OperationContext $context): array
    {
        return DB::transaction(function () use ($id, $expectedVersion, $context): array {
            $reservation = $this->idempotency->reserve(
                $context->actor->id,
                'CHOOSE_CREDIT_BALANCE',
                $id,
                $context->idempotencyKey,
                ['lock_version' => $expectedVersion],
            );
            $replay = $this->idempotency->replay($reservation);
            if ($replay !== null) {
                return $replay;
            }

            $balance = ExcessBalanceModel::query()->whereKey($id)->lockForUpdate()->first()
                ?? throw ExcessBalanceException::notFound();
            $this->scope->assertOwner($context->actor, (int) $balance->distributor_id);
            $this->assertVersion($balance, $expectedVersion);
            if ($balance->status !== ExcessBalanceStatus::PENDING_DECISION
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
            $this->states->assertTransition($balance->status, ExcessBalanceStatus::CREDIT_BALANCE);
            $balance->forceFill([
                'retained_amount' => '0.0000',
                'available_amount' => $amount->value(),
                'status' => ExcessBalanceStatus::CREDIT_BALANCE,
                'decision' => ExcessBalanceStatus::CREDIT_BALANCE->value,
                'decided_by' => $context->actor->id,
                'decided_at' => now('UTC'),
                'lock_version' => $balance->lock_version + 1,
            ])->save();
            $this->assertInvariant($balance);
            $after = $this->recorder->amounts($balance);
            $operationKey = 'credit:'.$balance->id.':'.$context->idempotencyKey;
            $this->recorder->ledger(
                $balance->id,
                ExcessLedgerEntryType::MARKED_AS_CREDIT,
                $amount->value(),
                ExcessBalanceBucket::RETAINED,
                ExcessBalanceBucket::AVAILABLE,
                $operationKey,
                $context->actor->id,
            );
            $this->recorder->history(
                $balance,
                $previousStatus,
                $balance->status->value,
                $before,
                $after,
                $operationKey,
                $context->actor->id,
            );
            $this->recorder->audit(
                'EXCESS_MARKED_AS_CREDIT',
                'SUCCESS',
                'excess_balances',
                $balance->id,
                $context,
                $before,
                $after,
            );
            $this->recorder->event('ExcessMarkedAsCredit', $balance, [
                'amount' => $amount->value(),
                'correlation_id' => $context->correlationId,
            ], $operationKey);
            $response = [
                'id' => $balance->id,
                'status' => $balance->status->value,
                'available_amount' => (string) $balance->available_amount,
                'lock_version' => (int) $balance->lock_version,
            ];
            $this->idempotency->complete((string) $reservation->id, 200, $response);

            return $response;
        }, 3);
    }

    private function assertVersion(ExcessBalanceModel $balance, int $expectedVersion): void
    {
        if ((int) $balance->lock_version !== $expectedVersion) {
            throw ExcessBalanceException::stateConflict();
        }
    }

    private function normalizeLegacyPendingBalance(ExcessBalanceModel $balance): void
    {
        $retained = new Money((string) $balance->retained_amount);
        $available = new Money((string) $balance->available_amount);
        if ($retained->isPositive() || ! $available->isPositive()) {
            return;
        }

        $balance->forceFill([
            'retained_amount' => $available->value(),
            'available_amount' => '0.0000',
        ]);
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
}
