<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\Contracts\ResolveClientForCashierVerification;
use App\Modules\Client\Application\Security\ClientActorContext;
use App\Modules\Credit\Application\Contracts\CreditVoucherGateway;
use App\Modules\Credit\Application\DTOs\VoucherCapitalUsage;
use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\RiskDelinquency\Application\Contracts\CanDistributorIssueVoucher;
use App\Modules\Voucher\Application\Contracts\CreditBalanceSnapshotPort;
use App\Modules\Voucher\Application\Contracts\ModificationRequestRepository;
use App\Modules\Voucher\Application\Contracts\VoucherEligibilityPort;
use App\Modules\Voucher\Application\Contracts\VoucherRepository;
use App\Modules\Voucher\Application\DTOs\OperationMetadata;
use App\Modules\Voucher\Application\Security\VoucherActorContext;
use App\Modules\Voucher\Domain\Enums\VoucherRejectionReason;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Domain\Services\TransactionNumberProtector;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherFulfillmentModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Orquesta búsqueda, apertura, liberación, rechazo y feriado. */
final readonly class CounterVoucherService
{
    private const RELEASE_CHECKS = [
        'identity_verified',
        'address_verified',
        'identification_verified',
        'proof_of_address_verified',
        'bank_account_verified',
    ];

    public function __construct(
        private VoucherRepository $vouchers,
        private ModificationRequestRepository $modifications,
        private VoucherEligibilityPort $eligibility,
        private ResolveClientForCashierVerification $clientVerification,
        private CreditVoucherGateway $credit,
        private CreditBalanceSnapshotPort $creditBalances,
        private CanDistributorIssueVoucher $delinquency,
        private TransactionNumberProtector $transactions,
        private IdempotencyService $idempotency,
        private VoucherRecorder $recorder,
        private SensitiveReasonGuard $reasons,
        private VoucherDataBuilder $data,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, VoucherModel>
     */
    public function search(VoucherActorContext $actor, array $filters): LengthAwarePaginator
    {
        $this->assertCanView($actor);

        return $this->vouchers->search($actor, $filters);
    }

    /** @return array<string, mixed> */
    public function detail(string $id, VoucherActorContext $actor, OperationMetadata $metadata): array
    {
        $this->assertCanView($actor);
        $voucher = $this->vouchers->findScoped($id, $actor);
        $data = $this->voucherData($voucher);
        $categories = ['voucher', 'financial_snapshot'];
        if ($actor->role === RoleCode::CASHIER) {
            $client = $this->clientVerification->handle(
                $voucher->client_id,
                $voucher->id,
                $this->clientActor($actor),
                $metadata->requestId,
            );
            $data['client'] = [
                'id' => $client->clientId,
                'display_name' => $client->displayName,
                'address' => $client->address,
                'bank_account' => $client->bankAccount,
                'documents' => $client->documents,
            ];
            $categories[] = 'client_verification';
        }
        $this->recorder->audit(
            'VOUCHER_DETAIL_VIEWED',
            'SUCCESS',
            $voucher->id,
            $actor,
            $metadata,
            ['categories' => $categories],
        );

        return $data;
    }

    /** @return array<string, mixed> */
    public function open(string $id, int $expectedVersion, VoucherActorContext $actor, OperationMetadata $metadata): array
    {
        $this->assertCashier($actor, PermissionCode::VOUCHERS_OPEN_AT_COUNTER);

        return DB::transaction(function () use ($id, $expectedVersion, $actor, $metadata): array {
            $reservation = $this->idempotency->reserve($actor->userId, 'OPEN_AT_COUNTER', $metadata->idempotencyKey, [
                'voucher_id' => $id,
                'lock_version' => $expectedVersion,
            ]);
            if ($reservation->completed_at !== null) {
                return $reservation->response_payload ?? [];
            }
            $voucher = $this->vouchers->lockScoped($id, $actor);
            $this->assertVersion($voucher, $expectedVersion);
            $before = $voucher->status;
            $aggregate = $voucher->toAggregate();
            $aggregate->openAtCounter();
            $voucher->forceFill([
                'status' => $aggregate->status(),
                'lock_version' => $aggregate->lockVersion(),
                'opened_by' => $actor->userId,
                'opened_at' => now('UTC'),
            ])->save();
            $this->recorder->operation(
                $voucher->id,
                'VOUCHER_OPENED_AT_COUNTER',
                $before->value,
                $voucher->status->value,
                $actor,
                $metadata,
            );
            $this->recorder->event('VoucherOpenedAtCounter', $voucher->id, 'voucher-opened:'.$voucher->id, [
                'voucher_id' => $voucher->id,
                'cashier_id' => $actor->publicId,
                'branch_id' => $actor->branchPublicId,
                'opened_at' => $voucher->opened_at->toIso8601String(),
            ]);
            $response = $this->voucherData($voucher);
            $this->idempotency->complete($reservation, 200, $response);

            return $response;
        }, 3);
    }

    /** @param array<string, bool> $checks
     * @return array<string, mixed>
     */
    public function release(
        string $id,
        int $expectedVersion,
        array $checks,
        VoucherActorContext $actor,
        OperationMetadata $metadata,
    ): array {
        $this->assertCashier($actor, PermissionCode::VOUCHERS_RELEASE);
        $providedChecks = array_keys($checks);
        $requiredChecks = self::RELEASE_CHECKS;
        sort($providedChecks);
        sort($requiredChecks);
        if ($providedChecks !== $requiredChecks || in_array(false, $checks, true)) {
            throw VoucherDomainException::correctionPending();
        }

        return DB::transaction(function () use ($id, $expectedVersion, $checks, $actor, $metadata): array {
            $reservation = $this->idempotency->reserve($actor->userId, 'RELEASE', $metadata->idempotencyKey, [
                'voucher_id' => $id,
                'lock_version' => $expectedVersion,
                'checks' => $checks,
            ]);
            if ($reservation->completed_at !== null) {
                return $reservation->response_payload ?? [];
            }
            $voucher = $this->vouchers->lockScoped($id, $actor);
            $this->assertVersion($voucher, $expectedVersion);
            if ($this->modifications->hasActiveForVoucher($voucher->id)) {
                throw VoucherDomainException::correctionPending();
            }
            $eligibility = $this->eligibility->forRelease($voucher);
            $before = $voucher->status;
            $aggregate = $voucher->toAggregate();
            $aggregate->release();
            $releasedAt = now('UTC');
            $voucher->forceFill([
                'status' => $aggregate->status(),
                'lock_version' => $aggregate->lockVersion(),
                'released_by' => $actor->userId,
                'released_at' => $releasedAt,
            ])->save();
            $fulfillment = new VoucherFulfillmentModel;
            $fulfillment->forceFill([
                'voucher_id' => $voucher->id,
                'branch_id' => $voucher->branch_id,
                'client_bank_account_id' => $eligibility->clientBankAccountId,
                'capital_amount' => $voucher->capital_amount,
                'released_by' => $actor->userId,
                'released_at' => $releasedAt,
            ])->save();
            $this->recorder->operation(
                $voucher->id,
                'VOUCHER_RELEASED',
                $before->value,
                $voucher->status->value,
                $actor,
                $metadata,
                ['checks' => array_keys($checks)],
            );
            $this->recorder->event('VoucherReleased', $voucher->id, 'voucher-released:'.$voucher->id, [
                'voucher_id' => $voucher->id,
                'cashier_id' => $actor->publicId,
                'branch_id' => $actor->branchPublicId,
                'released_at' => $releasedAt->toIso8601String(),
            ]);
            $response = $this->voucherData($voucher);
            $this->idempotency->complete($reservation, 200, $response);

            return $response;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function reject(
        string $id,
        int $expectedVersion,
        VoucherRejectionReason $reason,
        string $description,
        VoucherActorContext $actor,
        OperationMetadata $metadata,
    ): array {
        $this->assertCashier($actor, PermissionCode::VOUCHERS_REJECT);
        $description = $this->reasons->sanitize($description, 'description');

        return DB::transaction(function () use ($id, $expectedVersion, $reason, $description, $actor, $metadata): array {
            $reservation = $this->idempotency->reserve($actor->userId, 'REJECT', $metadata->idempotencyKey, [
                'voucher_id' => $id,
                'lock_version' => $expectedVersion,
                'reason_code' => $reason->value,
                'description' => $description,
            ]);
            if ($reservation->completed_at !== null) {
                return $reservation->response_payload ?? [];
            }
            $voucher = $this->vouchers->lockScoped($id, $actor);
            $this->assertVersion($voucher, $expectedVersion);
            $eligibility = $this->eligibility->forRejection($voucher);
            $before = $voucher->status;
            $aggregate = $voucher->toAggregate();
            $aggregate->reject();
            $this->credit->releaseRestriction($eligibility->creditDistributorId, $voucher->id, $actor->userId);
            $voucher->forceFill([
                'status' => $aggregate->status(),
                'lock_version' => $aggregate->lockVersion(),
                'rejected_by' => $actor->userId,
                'rejected_at' => now('UTC'),
                'rejection_reason_code' => $reason->value,
                'rejection_description' => $description,
            ])->save();
            $this->recorder->operation(
                $voucher->id,
                'VOUCHER_REJECTED',
                $before->value,
                $voucher->status->value,
                $actor,
                $metadata,
                ['reason_code' => $reason->value],
            );
            $this->recorder->event('VoucherRejected', $voucher->id, 'voucher-rejected:'.$voucher->id, [
                'voucher_id' => $voucher->id,
                'cashier_id' => $actor->publicId,
                'reason_code' => $reason->value,
                'rejected_at' => $voucher->rejected_at->toIso8601String(),
            ]);
            $response = $this->voucherData($voucher);
            $this->idempotency->complete($reservation, 200, $response);

            return $response;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function fulfill(
        string $id,
        int $expectedVersion,
        string $transactionNumber,
        VoucherActorContext $actor,
        OperationMetadata $metadata,
    ): array {
        $this->assertCashier($actor, PermissionCode::VOUCHERS_FULFILL);

        try {
            return DB::transaction(function () use ($id, $expectedVersion, $transactionNumber, $actor, $metadata): array {
                $reservation = $this->idempotency->reserve($actor->userId, 'FULFILL', $metadata->idempotencyKey, [
                    'voucher_id' => $id,
                    'lock_version' => $expectedVersion,
                    'transaction_number_hash' => hash('sha256', trim($transactionNumber)),
                ]);
                if ($reservation->completed_at !== null) {
                    return $reservation->response_payload ?? [];
                }
                $voucher = $this->vouchers->lockScoped($id, $actor);
                $this->assertVersion($voucher, $expectedVersion);
                $eligibility = $this->eligibility->forFulfillment($voucher);
                $this->delinquency->assertAllowed($eligibility->creditDistributorId);
                $fulfillment = VoucherFulfillmentModel::query()
                    ->where('voucher_id', $voucher->id)
                    ->lockForUpdate()
                    ->first();
                if ($fulfillment === null) {
                    throw VoucherDomainException::invalidTransition();
                }
                if (
                    $fulfillment->client_bank_account_id !== $eligibility->clientBankAccountId
                    || bccomp($fulfillment->capital_amount, $voucher->capital_amount, 4) !== 0
                ) {
                    throw VoucherDomainException::invalidTransition();
                }
                $protected = $this->transactions->protect($transactionNumber);
                if (VoucherFulfillmentModel::query()
                    ->where('transaction_number_hmac', $protected['hmac'])
                    ->where('voucher_id', '!=', $voucher->id)
                    ->exists()) {
                    throw VoucherDomainException::transactionDuplicate();
                }
                $before = $voucher->status;
                $aggregate = $voucher->toAggregate();
                $aggregate->fulfill();
                $creditIdempotency = hash('sha256', 'voucher-fulfilled|'.$voucher->id.'|'.$metadata->idempotencyKey);
                $movementId = $this->credit->applyFulfilledVoucher(new VoucherCapitalUsage(
                    distributorId: $eligibility->creditDistributorId,
                    voucherId: $voucher->id,
                    capital: new Money($voucher->capital_amount),
                    actorUserId: $actor->userId,
                    branchId: $voucher->branch_id,
                    reason: 'Feriado de vale liberado.',
                    idempotencyKey: $creditIdempotency,
                ));
                $fulfilledAt = now('UTC');
                $fulfillment->forceFill([
                    'transaction_number_encrypted' => $protected['encrypted'],
                    'transaction_number_hmac' => $protected['hmac'],
                    'fulfilled_by' => $actor->userId,
                    'fulfilled_at' => $fulfilledAt,
                    'lock_version' => $fulfillment->lock_version + 1,
                ])->save();
                $voucher->forceFill([
                    'status' => $aggregate->status(),
                    'lock_version' => $aggregate->lockVersion(),
                    'fulfilled_by' => $actor->userId,
                    'fulfilled_at' => $fulfilledAt,
                ])->save();
                $balance = $this->creditBalances->forDistributor($eligibility->creditDistributorId);
                $this->recorder->operation(
                    $voucher->id,
                    'VOUCHER_FULFILLED',
                    $before->value,
                    $voucher->status->value,
                    $actor,
                    $metadata,
                    [
                        'transaction_number_masked' => $protected['masked'],
                        'capital' => $voucher->capital_amount,
                        'credit_before_after_available' => $balance->available,
                    ],
                );
                $this->recorder->event('VoucherFulfilled', $voucher->id, 'voucher-fulfilled:'.$voucher->id, [
                    'voucher_id' => $voucher->id,
                    'distributor_id' => $voucher->distributor_id,
                    'capital' => $voucher->capital_amount,
                    'movement_id' => $movementId,
                    'fulfilled_at' => $fulfilledAt->toIso8601String(),
                ]);
                $response = [
                    ...$this->voucherData($voucher),
                    'credit_line' => [
                        'total' => $balance->total,
                        'used' => $balance->used,
                        'available' => $balance->available,
                    ],
                ];
                $this->idempotency->complete($reservation, 200, $response);

                return $response;
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'transaction_number_hmac')) {
                throw VoucherDomainException::transactionDuplicate($exception);
            }

            throw $exception;
        }
    }

    private function assertCanView(VoucherActorContext $actor): void
    {
        if (! $actor->hasPermission(PermissionCode::VOUCHERS_VIEW->value)) {
            throw VoucherDomainException::scopeDenied();
        }
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

    private function assertVersion(VoucherModel $voucher, int $expected): void
    {
        if ($voucher->lock_version !== $expected) {
            throw VoucherDomainException::versionConflict();
        }
    }

    /** @return array<string, mixed> */
    private function voucherData(VoucherModel $voucher): array
    {
        return $this->data->build($voucher);
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
