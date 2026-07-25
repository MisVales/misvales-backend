<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Portfolio;

use App\Modules\Client\Application\Contracts\ClientAuditPort;
use App\Modules\Client\Application\Contracts\ClientOutboxPort;
use App\Modules\Client\Application\Contracts\ConfirmedVoucherPort;
use App\Modules\Client\Application\Contracts\RecordClientVoucherReference;
use App\Modules\Client\Application\Contracts\RecordClientVoucherReferenceCommand;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Domain\Portfolio\PortfolioBalance;
use App\Modules\Client\Domain\Portfolio\PortfolioEntryType;
use App\Modules\Client\Domain\Portfolio\PortfolioStatus;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Client\Persistence\Models\ClientPortfolioEntry;
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Registra una referencia de M08 sin crear, autorizar ni modificar el vale. */
final readonly class RecordVoucherReference implements RecordClientVoucherReference
{
    public function __construct(
        private ConfirmedVoucherPort $vouchers,
        private ClientAuditPort $audit,
        private ClientOutboxPort $outbox,
    ) {}

    public function handle(RecordClientVoucherReferenceCommand $command): void
    {
        foreach ([
            'client_id' => $command->clientId,
            'distributor_id' => $command->distributorId,
            'voucher_id' => $command->voucherId,
            'operation_id' => $command->operationId,
            'request_id' => $command->requestId,
        ] as $field => $identifier) {
            if (! Str::isUuid($identifier)) {
                throw ClientDomainException::dataIncomplete($field);
            }
        }
        $amount = PortfolioBalance::normalize($command->amount);
        $this->vouchers->assertConfirmedForClient(
            $command->voucherId,
            $command->clientId,
            $command->distributorId,
            $amount,
        );

        try {
            DB::transaction(function () use ($command, $amount): void {
                $existing = ClientPortfolioEntry::query()->where('voucher_id', $command->voucherId)->lockForUpdate()->first();
                if ($existing !== null) {
                    if (
                        $existing->client_id !== $command->clientId
                        || $existing->distributor_id !== $command->distributorId
                        || bccomp((string) $existing->amount, $amount, 4) !== 0
                    ) {
                        throw ClientDomainException::idempotencyConflict();
                    }

                    return;
                }
                $assignment = ClientDistributorAssignment::query()
                    ->where('client_id', $command->clientId)
                    ->where('distributor_id', $command->distributorId)
                    ->where('active_slot', true)
                    ->lockForUpdate()
                    ->first();
                if ($assignment === null) {
                    throw ClientDomainException::notAssigned();
                }
                $setting = ClientPortfolioSetting::query()->where('assignment_id', $assignment->id)->lockForUpdate()->firstOrFail();

                $entry = new ClientPortfolioEntry;
                $entry->forceFill([
                    'id' => (string) Str::uuid(),
                    'client_id' => $command->clientId,
                    'distributor_id' => $command->distributorId,
                    'assignment_id' => $assignment->id,
                    'voucher_id' => $command->voucherId,
                    'entry_type' => PortfolioEntryType::VOUCHER,
                    'amount' => $amount,
                    'informational_status' => PortfolioStatus::PENDING,
                    'occurred_on' => $command->occurredOn,
                    'note' => null,
                    'created_by' => $command->actor->userId,
                    'idempotency_key' => $command->operationId,
                    'request_hash' => hash('sha256', $command->voucherId.'|'.$amount),
                    'lock_version' => 1,
                ])->save();
                $setting->forceFill([
                    'lock_version' => $setting->lock_version + 1,
                    'updated_by' => $command->actor->userId,
                ])->save();

                $this->audit->record(
                    'CLIENT_VOUCHER_REFERENCE_RECORDED',
                    $command->clientId,
                    $command->actor,
                    $command->distributorId,
                    $command->operationId,
                    ['voucher_reference', 'amount'],
                    'SUCCESS',
                    $command->requestId,
                );
                $this->outbox->append('ClientPortfolioEntryRecorded', [
                    'client_id' => $command->clientId,
                    'distributor_id' => $command->distributorId,
                    'entry_id' => $entry->id,
                    'entry_type' => PortfolioEntryType::VOUCHER->value,
                    'recorded_at' => now()->toIso8601String(),
                ], 'client-voucher-reference:'.$command->voucherId);
            }, 3);
        } catch (QueryException $exception) {
            $message = mb_strtolower($exception->getMessage());
            if (str_contains($message, 'voucher_id') || str_contains($message, 'idempotency_key')) {
                $existing = ClientPortfolioEntry::query()->where('voucher_id', $command->voucherId)->first();
                if (
                    $existing !== null
                    && $existing->client_id === $command->clientId
                    && $existing->distributor_id === $command->distributorId
                    && bccomp((string) $existing->amount, $amount, 4) === 0
                ) {
                    return;
                }
                throw ClientDomainException::idempotencyConflict();
            }

            throw $exception;
        }
    }
}
