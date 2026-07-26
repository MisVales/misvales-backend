<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Services;

use App\Modules\ExcessBalance\Application\Contracts\ExcessReauthenticationPort;
use App\Modules\ExcessBalance\Application\Contracts\PrivateEvidencePort;
use App\Modules\ExcessBalance\Application\Contracts\RefundExecutionPolicy;
use App\Modules\ExcessBalance\Application\DTOs\OperationContext;
use App\Modules\ExcessBalance\Application\Security\ExcessScopeService;
use App\Modules\ExcessBalance\Domain\Enums\ExcessBalanceBucket;
use App\Modules\ExcessBalance\Domain\Enums\ExcessLedgerEntryType;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use App\Modules\ExcessBalance\Domain\Services\ExcessBalanceInvariant;
use App\Modules\ExcessBalance\Domain\ValueObjects\Money;
use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use App\Modules\Payment\Domain\Enums\RefundRequestStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RefundWorkflowService
{
    public function __construct(
        private ExcessScopeService $scope,
        private ExcessIdempotencyService $idempotency,
        private ExcessReauthenticationPort $reauthentication,
        private RefundExecutionPolicy $executionPolicy,
        private PrivateEvidencePort $evidence,
        private ExcessBalanceInvariant $invariant,
        private ExcessRecorder $recorder,
    ) {}

    /** @return array<string, mixed> */
    public function authorize(
        string $id,
        int $expectedVersion,
        string $reauthenticationToken,
        ?string $reason,
        OperationContext $context,
    ): array {
        return $this->decide(
            $id,
            $expectedVersion,
            RefundRequestStatus::AUTHORIZED,
            $reauthenticationToken,
            $reason,
            $context,
        );
    }

    /** @return array<string, mixed> */
    public function reject(
        string $id,
        int $expectedVersion,
        string $reauthenticationToken,
        string $reason,
        OperationContext $context,
    ): array {
        return $this->decide(
            $id,
            $expectedVersion,
            RefundRequestStatus::REJECTED,
            $reauthenticationToken,
            $reason,
            $context,
        );
    }

    /**
     * @param  array<string, mixed>  $executionFields
     * @return array<string, mixed>
     */
    public function complete(
        string $id,
        int $expectedVersion,
        CarbonImmutable $refundDate,
        string $method,
        string $reference,
        ?UploadedFile $evidence,
        array $executionFields,
        OperationContext $context,
    ): array {
        return DB::transaction(function () use (
            $id,
            $expectedVersion,
            $refundDate,
            $method,
            $reference,
            $evidence,
            $executionFields,
            $context,
        ): array {
            $reservation = $this->idempotency->reserve(
                $context->actor->id,
                'COMPLETE_REFUND',
                $id,
                $context->idempotencyKey,
                [
                    'lock_version' => $expectedVersion,
                    'refund_date' => $refundDate->toDateString(),
                    'method' => $method,
                    'reference' => $reference,
                    'fields' => $executionFields,
                    'evidence_hash' => $evidence?->hashName(),
                ],
            );
            $replay = $this->idempotency->replay($reservation);
            if ($replay !== null) {
                return $replay;
            }

            [$balance, $request] = $this->lockBalanceAndRequest($id);
            $this->scope->assertCashier($context->actor, (int) $request->branch_id);
            if ((int) $request->lock_version !== $expectedVersion
                || $request->status !== RefundRequestStatus::AUTHORIZED) {
                throw ExcessBalanceException::refundStateConflict();
            }
            if ((int) $request->requested_by === $context->actor->id
                || (int) $request->authorized_by === $context->actor->id) {
                throw ExcessBalanceException::authorizationDenied();
            }

            $this->executionPolicy->validate($method, $executionFields);
            $requested = new Money((string) $request->amount);
            $reserved = new Money((string) $balance->reserved_refund_amount);
            if (! $requested->isPositive() || ! $requested->equals($reserved)) {
                throw ExcessBalanceException::insufficientReserved();
            }
            $storedEvidenceId = null;
            if ($evidence !== null) {
                $stored = $this->evidence->store($evidence, 'refund:'.$request->id);
                $storedEvidenceId = (string) Str::uuid();
                DB::table('excess_evidence_files')->insert([
                    'id' => $storedEvidenceId,
                    'storage_file_id' => $stored->storageFileId,
                    'sha256' => $stored->sha256,
                    'size_bytes' => $stored->sizeBytes,
                    'detected_mime' => $stored->detectedMime,
                    'uploaded_by' => $context->actor->id,
                    'uploaded_at' => now('UTC'),
                    'created_at' => now('UTC'),
                ]);
            }

            $before = $this->recorder->amounts($balance);
            $previousStatus = $balance->status->value;
            $request->forceFill([
                'status' => RefundRequestStatus::COMPLETED,
                'executed_by' => $context->actor->id,
                'completed_at' => now('UTC'),
                'refund_date' => $refundDate,
                'refund_method' => $method,
                'refund_reference' => $reference,
                'evidence_media_file_id' => $storedEvidenceId,
                'lock_version' => $request->lock_version + 1,
            ])->save();
            $balance->forceFill([
                'reserved_refund_amount' => '0.0000',
                'refunded_amount' => $requested->value(),
                'status' => ExcessBalanceStatus::REFUNDED,
                'lock_version' => $balance->lock_version + 1,
            ])->save();
            $this->assertInvariant($balance);
            $after = $this->recorder->amounts($balance);
            $operationKey = 'refund-completed:'.$request->id.':'.$context->idempotencyKey;
            $this->recorder->ledger(
                $balance->id,
                ExcessLedgerEntryType::REFUND_COMPLETED,
                $requested->value(),
                ExcessBalanceBucket::RESERVED,
                ExcessBalanceBucket::REFUNDED,
                $operationKey,
                $context->actor->id,
                refundId: $request->id,
                metadata: ['method' => $method],
            );
            $this->recorder->history(
                $balance,
                $previousStatus,
                $balance->status->value,
                $before,
                $after,
                $operationKey,
                $context->actor->id,
                refundId: $request->id,
            );
            $this->recorder->audit(
                'REFUND_COMPLETED',
                'SUCCESS',
                'refund_requests',
                $request->id,
                $context,
                $before,
                $after,
                [
                    'method' => $method,
                    'reference_hash' => hash('sha256', $reference),
                    'evidence_file_id' => $storedEvidenceId,
                ],
            );
            $this->recorder->event('RefundCompleted', $balance, [
                'refund_request_id' => $request->id,
                'amount' => $requested->value(),
                'correlation_id' => $context->correlationId,
            ], $operationKey);
            $response = $this->requestResponse($request);
            $this->idempotency->complete((string) $reservation->id, 200, $response);

            return $response;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function decide(
        string $id,
        int $expectedVersion,
        RefundRequestStatus $decision,
        string $reauthenticationToken,
        ?string $reason,
        OperationContext $context,
    ): array {
        return DB::transaction(function () use (
            $id,
            $expectedVersion,
            $decision,
            $reauthenticationToken,
            $reason,
            $context,
        ): array {
            $operation = $decision === RefundRequestStatus::AUTHORIZED ? 'AUTHORIZE_REFUND' : 'REJECT_REFUND';
            $reservation = $this->idempotency->reserve(
                $context->actor->id,
                $operation,
                $id,
                $context->idempotencyKey,
                [
                    'lock_version' => $expectedVersion,
                    'decision' => $decision->value,
                    'reason' => $reason,
                ],
            );
            $replay = $this->idempotency->replay($reservation);
            if ($replay !== null) {
                return $replay;
            }

            [$balance, $request] = $this->lockBalanceAndRequest($id);
            $this->scope->assertManager($context->actor, (int) $request->branch_id);
            if ((int) $request->lock_version !== $expectedVersion
                || $request->status !== RefundRequestStatus::PENDING_AUTHORIZATION
                || $balance->status !== ExcessBalanceStatus::REFUND_PENDING) {
                throw ExcessBalanceException::refundStateConflict();
            }
            if ((int) $request->requested_by === $context->actor->id) {
                throw ExcessBalanceException::authorizationDenied();
            }
            $this->assertInvariant($balance);
            $this->reauthentication->consume(
                $context->actor,
                $reauthenticationToken,
                $request,
                $decision->value,
                $reason,
            );

            $request->forceFill([
                'status' => $decision,
                'authorized_by' => $context->actor->id,
                'decided_at' => now('UTC'),
                'decision_reason' => $reason,
                'lock_version' => $request->lock_version + 1,
            ])->save();
            $operationKey = strtolower($decision->name).':'.$request->id.':'.$context->idempotencyKey;
            $event = $decision === RefundRequestStatus::AUTHORIZED ? 'RefundAuthorized' : 'RefundRejected';
            $this->recorder->audit(
                strtoupper($event),
                'SUCCESS',
                'refund_requests',
                $request->id,
                $context,
                ['status' => RefundRequestStatus::PENDING_AUTHORIZATION->value],
                ['status' => $decision->value],
                ['excess_balance_id' => $balance->id],
                $reason,
            );
            $this->recorder->event($event, $balance, [
                'refund_request_id' => $request->id,
                'amount' => (string) $request->amount,
                'correlation_id' => $context->correlationId,
            ], $operationKey);
            $response = $this->requestResponse($request);
            $this->idempotency->complete((string) $reservation->id, 200, $response);

            return $response;
        }, 3);
    }

    /** @return array{ExcessBalanceModel, RefundRequestModel} */
    private function lockBalanceAndRequest(string $requestId): array
    {
        $locator = RefundRequestModel::query()->whereKey($requestId)->first()
            ?? throw ExcessBalanceException::notFound();
        $balance = ExcessBalanceModel::query()->whereKey($locator->excess_balance_id)->lockForUpdate()->first()
            ?? throw ExcessBalanceException::notFound();
        $request = RefundRequestModel::query()->whereKey($requestId)->lockForUpdate()->first()
            ?? throw ExcessBalanceException::notFound();

        return [$balance, $request];
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

    /** @return array<string, mixed> */
    private function requestResponse(RefundRequestModel $request): array
    {
        return [
            'id' => $request->id,
            'request_number' => $request->request_number,
            'excess_balance_id' => $request->excess_balance_id,
            'status' => $request->status->value,
            'requested_amount' => (string) $request->amount,
            'decision_at' => $request->decided_at?->toIso8601String(),
            'completed_at' => $request->completed_at?->toIso8601String(),
            'lock_version' => (int) $request->lock_version,
        ];
    }
}
