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
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Client\Persistence\Models\ClientPortfolioConfirmation;
use App\Modules\Client\Persistence\Models\ClientPortfolioEntry;
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Conserva quién confirmó saldo registrado en cero y sobre qué versión. */
final readonly class ConfirmZeroPortfolio
{
    public function __construct(
        private DistributorProfilePort $profiles,
        private ClientAuditPort $audit,
        private ClientOutboxPort $outbox,
    ) {}

    public function execute(ConfirmZeroPortfolioCommand $command): ClientPortfolioConfirmation
    {
        if (
            $command->actor->role !== RoleCode::DISTRIBUTOR
            || ! $command->actor->hasPermission(PermissionCode::CLIENTS_PORTFOLIO_WRITE_OWN->value)
        ) {
            throw ClientDomainException::authorizationDenied();
        }
        $profile = $this->profiles->forAuthenticatedDistributor($command->actor->userId);

        try {
            return DB::transaction(function () use ($command, $profile): ClientPortfolioConfirmation {
                $existing = ClientPortfolioConfirmation::query()->where('operation_id', $command->operationId)->first();
                if ($existing !== null) {
                    if (
                        $existing->client_id !== $command->clientId
                        || $existing->distributor_id !== $profile->distributorId
                        || (int) $existing->confirmed_by !== $command->actor->userId
                        || (int) $existing->portfolio_version !== $command->expectedPortfolioVersion
                    ) {
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
                $setting = ClientPortfolioSetting::query()->where('assignment_id', $assignment->id)->lockForUpdate()->firstOrFail();
                if ($setting->lock_version !== $command->expectedPortfolioVersion) {
                    throw ClientDomainException::versionConflict('PORTFOLIO_VERSION_CONFLICT');
                }
                $total = PortfolioBalance::calculate(
                    ClientPortfolioEntry::query()
                        ->where('assignment_id', $assignment->id)
                        ->get(['entry_type', 'amount'])
                        ->map(static fn (ClientPortfolioEntry $entry): array => [
                            'entry_type' => $entry->entry_type->value,
                            'amount' => $entry->amount,
                        ]),
                );
                if (bccomp($total, '0.0000', 4) !== 0) {
                    throw ClientDomainException::transferBalanceNotZero();
                }

                $confirmation = new ClientPortfolioConfirmation;
                $confirmation->forceFill([
                    'id' => (string) Str::uuid(),
                    'client_id' => $command->clientId,
                    'distributor_id' => $profile->distributorId,
                    'assignment_id' => $assignment->id,
                    'total_balance' => '0.0000',
                    'overdue_balance' => null,
                    'portfolio_version' => $setting->lock_version,
                    'confirmed_by' => $command->actor->userId,
                    'confirmed_at' => now(),
                    'operation_id' => $command->operationId,
                ])->save();

                $this->audit->record(
                    'CLIENT_PORTFOLIO_TRANSFER_BALANCE_CONFIRMED',
                    $command->clientId,
                    $command->actor,
                    $profile->distributorId,
                    $command->operationId,
                    ['portfolio_version'],
                    'SUCCESS',
                    $command->requestId,
                );
                $this->outbox->append('ClientPortfolioTransferBalanceConfirmed', [
                    'client_id' => $command->clientId,
                    'distributor_id' => $profile->distributorId,
                    'portfolio_version' => $setting->lock_version,
                    'confirmed_at' => now()->toIso8601String(),
                ], 'client-portfolio-confirmed:'.$command->operationId);

                return $confirmation->refresh();
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'operation_id')) {
                $existing = ClientPortfolioConfirmation::query()->where('operation_id', $command->operationId)->first();
                if (
                    $existing !== null
                    && $existing->client_id === $command->clientId
                    && $existing->distributor_id === $profile->distributorId
                ) {
                    return $existing;
                }
                throw ClientDomainException::idempotencyConflict();
            }

            throw $exception;
        }
    }
}
