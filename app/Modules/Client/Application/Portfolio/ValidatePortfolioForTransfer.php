<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Portfolio;

use App\Modules\Client\Application\Contracts\ClientTransferBalanceResult;
use App\Modules\Client\Application\Contracts\ValidateClientPortfolioForTransfer;
use App\Modules\Client\Application\Contracts\ValidateClientPortfolioForTransferQuery;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Domain\Portfolio\PortfolioBalance;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Client\Persistence\Models\ClientPortfolioConfirmation;
use App\Modules\Client\Persistence\Models\ClientPortfolioEntry;
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;

/** Calcula la condición de saldo cero sin convertirla en elegibilidad del cliente. */
final class ValidatePortfolioForTransfer implements ValidateClientPortfolioForTransfer
{
    public function handle(ValidateClientPortfolioForTransferQuery $query): ClientTransferBalanceResult
    {
        $assignment = ClientDistributorAssignment::query()
            ->where('client_id', $query->clientId)
            ->where('distributor_id', $query->sourceDistributorId)
            ->where('active_slot', true)
            ->first();
        if ($assignment === null) {
            throw ClientDomainException::notAssigned();
        }
        $setting = ClientPortfolioSetting::query()->where('assignment_id', $assignment->id)->firstOrFail();
        if ($setting->lock_version !== $query->expectedPortfolioVersion) {
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
        $confirmation = ClientPortfolioConfirmation::query()
            ->where('assignment_id', $assignment->id)
            ->where('portfolio_version', $setting->lock_version)
            ->latest('confirmed_at')
            ->first();
        $allowed = bccomp($total, '0.0000', 4) === 0
            && ($setting->tracking_enabled || $confirmation !== null);

        return new ClientTransferBalanceResult(
            clientId: $query->clientId,
            distributorId: $query->sourceDistributorId,
            totalBalance: $total,
            overdueBalance: null,
            trackingEnabled: $setting->tracking_enabled,
            confirmedAt: $confirmation?->confirmed_at?->toIso8601String(),
            confirmedBy: $confirmation === null ? null : (int) $confirmation->confirmed_by,
            portfolioVersion: $setting->lock_version,
            allowed: $allowed,
        );
    }
}
