<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Payment\Application\Contracts\PaymentAuditPort;
use App\Modules\Payment\Application\Contracts\PaymentOutboxPort;
use App\Modules\Payment\Application\Security\PaymentActorContext;
use App\Modules\Payment\Application\Security\PaymentAuthorizer;
use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use App\Modules\Payment\Domain\Services\ExcessLedger;
use App\Modules\Payment\Domain\ValueObjects\Money;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use Illuminate\Support\Facades\DB;

/** Convierte el excedente completo disponible en saldo a favor, una sola vez. */
final readonly class ChooseExcessAsCredit
{
    public function __construct(
        private PaymentAuthorizer $authorizer,
        private PaymentIdempotencyService $idempotency,
        private PaymentAuditPort $audit,
        private PaymentOutboxPort $outbox,
        private ExcessLedger $ledger,
    ) {}

    /** @return array<string, mixed> */
    public function execute(
        string $excessId,
        int $expectedVersion,
        PaymentActorContext $actor,
        string $idempotencyKey,
        string $requestId,
    ): array {
        $this->authorizer->assertPermission($actor, PermissionCode::EXCESS_BALANCES_DECIDE_OWN);

        return DB::transaction(function () use ($excessId, $expectedVersion, $actor, $idempotencyKey, $requestId): array {
            $reservation = $this->idempotency->reserve($actor->userId, 'excess.choose_credit', $idempotencyKey, [
                'excess_id' => $excessId,
                'lock_version' => $expectedVersion,
            ]);
            if ($reservation->replay !== null) {
                return $reservation->replay;
            }

            $excess = ExcessBalanceModel::query()->whereKey($excessId)->lockForUpdate()->first()
                ?? throw PaymentDomainException::notFound();
            $this->authorizer->assertOwner($actor, (int) $excess->distributor_id);
            if ((int) $excess->lock_version !== $expectedVersion) {
                throw PaymentDomainException::versionConflict();
            }
            if ($excess->status !== ExcessBalanceStatus::PENDING_DECISION) {
                throw PaymentDomainException::excessDecisionTaken();
            }

            $available = new Money((string) $excess->available_amount);
            if (! $available->isPositive()) {
                throw PaymentDomainException::excessUnavailable();
            }
            $this->ledger->assertInvariant(
                new Money((string) $excess->original_amount),
                $available,
                new Money((string) $excess->applied_amount),
                new Money((string) $excess->refunded_amount),
                new Money((string) $excess->reserved_refund_amount),
            );
            $before = ['status' => $excess->status->value, 'lock_version' => $excess->lock_version];
            $excess->forceFill([
                'status' => ExcessBalanceStatus::CREDIT_BALANCE,
                'decision' => ExcessBalanceStatus::CREDIT_BALANCE->value,
                'decided_by' => $actor->userId,
                'decided_at' => now('UTC'),
                'lock_version' => $excess->lock_version + 1,
            ])->save();
            $response = [
                'id' => $excess->id,
                'status' => $excess->status->value,
                'available_amount' => (string) $excess->available_amount,
                'lock_version' => $excess->lock_version,
            ];
            $this->audit->record(
                'ExcessMarkedAsCredit',
                'SUCCESS',
                $actor,
                'excess_balances',
                $excess->id,
                $before,
                $response,
                ['distributor_id' => $actor->publicId],
                $requestId,
            );
            $this->outbox->append('ExcessMarkedAsCredit', [
                'excess_id' => $excess->id,
                'distributor_id' => $actor->publicId,
                'amount' => (string) $excess->available_amount,
                'occurred_at' => now('UTC')->toIso8601String(),
            ], "excess-credit:{$excess->id}");
            $this->idempotency->complete((string) $reservation->record->id, 200, $response);

            return $response;
        }, 3);
    }
}
