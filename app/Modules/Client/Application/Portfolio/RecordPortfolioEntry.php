<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Portfolio;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\Contracts\ClientAuditPort;
use App\Modules\Client\Application\Contracts\ClientOutboxPort;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Domain\Portfolio\PortfolioBalance;
use App\Modules\Client\Domain\Portfolio\PortfolioNoteSanitizer;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Client\Persistence\Models\ClientPortfolioEntry;
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Registra pagos, abonos, estado o nota sin alterar ningún libro financiero. */
final readonly class RecordPortfolioEntry
{
    public function __construct(
        private DistributorProfilePort $profiles,
        private ClientAuditPort $audit,
        private ClientOutboxPort $outbox,
        private PortfolioNoteSanitizer $notes,
    ) {}

    public function execute(RecordPortfolioEntryCommand $command): ClientPortfolioEntry
    {
        $this->assertAuthority($command);
        if (! $command->type->isDistributorWritable()) {
            throw ClientDomainException::portfolioInvalid('Los cargos de vale solo pueden provenir del contrato interno de M08.');
        }
        $profile = $this->profiles->forAuthenticatedDistributor($command->actor->userId);
        $amount = $this->validatedAmount($command);
        $note = $this->notes->normalize($command->note);
        $requestHash = hash('sha256', json_encode([
            $command->clientId,
            $command->type->value,
            $amount,
            $command->status->value,
            $command->occurredOn,
            $note,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        try {
            return DB::transaction(function () use ($command, $profile, $amount, $note, $requestHash): ClientPortfolioEntry {
                $existing = ClientPortfolioEntry::query()
                    ->where('distributor_id', $profile->distributorId)
                    ->where('idempotency_key', $command->idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        throw ClientDomainException::idempotencyConflict();
                    }

                    return $existing;
                }

                $assignment = ClientDistributorAssignment::query()
                    ->where('client_id', $command->clientId)
                    ->where('distributor_id', $profile->distributorId)
                    ->where('active_slot', true)
                    ->lockForUpdate()
                    ->first();
                if ($assignment === null) {
                    throw ClientDomainException::notFoundOrOutOfScope();
                }
                $setting = ClientPortfolioSetting::query()
                    ->where('assignment_id', $assignment->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (! $setting->tracking_enabled) {
                    throw ClientDomainException::portfolioDisabled();
                }

                if ($amount !== null) {
                    $balance = PortfolioBalance::calculate(
                        ClientPortfolioEntry::query()
                            ->where('assignment_id', $assignment->id)
                            ->get(['entry_type', 'amount'])
                            ->map(static fn (ClientPortfolioEntry $entry): array => [
                                'entry_type' => $entry->entry_type->value,
                                'amount' => $entry->amount,
                            ]),
                    );
                    if (bccomp($amount, $balance, 4) === 1) {
                        throw ClientDomainException::portfolioInvalid(
                            'El importe informado no puede generar automáticamente saldo a favor.',
                        );
                    }
                }

                $entry = new ClientPortfolioEntry;
                $entry->forceFill([
                    'id' => (string) Str::uuid(),
                    'client_id' => $command->clientId,
                    'distributor_id' => $profile->distributorId,
                    'assignment_id' => $assignment->id,
                    'voucher_id' => null,
                    'entry_type' => $command->type,
                    'amount' => $amount,
                    'informational_status' => $command->status,
                    'occurred_on' => $command->occurredOn,
                    'note' => $note,
                    'created_by' => $command->actor->userId,
                    'idempotency_key' => $command->idempotencyKey,
                    'request_hash' => $requestHash,
                    'lock_version' => 1,
                ])->save();

                $setting->forceFill([
                    'lock_version' => $setting->lock_version + 1,
                    'updated_by' => $command->actor->userId,
                ])->save();

                $this->audit->record(
                    'CLIENT_PORTFOLIO_ENTRY_RECORDED',
                    $command->clientId,
                    $command->actor,
                    $profile->distributorId,
                    null,
                    ['entry_type', 'amount', 'informational_status', 'occurred_on', 'note'],
                    'SUCCESS',
                    $command->requestId,
                );
                $this->outbox->append('ClientPortfolioEntryRecorded', [
                    'client_id' => $command->clientId,
                    'distributor_id' => $profile->distributorId,
                    'entry_id' => $entry->id,
                    'entry_type' => $command->type->value,
                    'recorded_at' => now()->toIso8601String(),
                ], 'client-portfolio-entry:'.$entry->id);

                return $entry->refresh();
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'idempotency_key')) {
                $existing = ClientPortfolioEntry::query()
                    ->where('distributor_id', $profile->distributorId)
                    ->where('idempotency_key', $command->idempotencyKey)
                    ->first();
                if ($existing !== null && hash_equals($existing->request_hash, $requestHash)) {
                    return $existing;
                }
                throw ClientDomainException::idempotencyConflict();
            }

            throw $exception;
        }
    }

    private function assertAuthority(RecordPortfolioEntryCommand $command): void
    {
        if (
            $command->actor->role !== RoleCode::DISTRIBUTOR
            || ! $command->actor->hasPermission(PermissionCode::CLIENTS_PORTFOLIO_WRITE_OWN->value)
        ) {
            throw ClientDomainException::authorizationDenied();
        }
    }

    private function validatedAmount(RecordPortfolioEntryCommand $command): ?string
    {
        if ($command->type->carriesAmount()) {
            if ($command->amount === null) {
                throw ClientDomainException::portfolioInvalid('El movimiento requiere un importe.');
            }

            return PortfolioBalance::normalize($command->amount);
        }
        if ($command->amount !== null) {
            throw ClientDomainException::portfolioInvalid('Este tipo de movimiento no admite importe.');
        }

        return null;
    }
}
