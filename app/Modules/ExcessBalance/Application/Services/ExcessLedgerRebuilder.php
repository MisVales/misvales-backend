<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Services;

use App\Modules\ExcessBalance\Domain\Enums\ExcessLedgerEntryType;
use App\Modules\ExcessBalance\Domain\ValueObjects\Money;
use App\Modules\ExcessBalance\Infrastructure\Persistence\Eloquent\Models\ExcessLedgerEntryModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;

final class ExcessLedgerRebuilder
{
    /** @return array<string, string> */
    public function rebuild(string $excessId): array
    {
        $retained = Money::zero();
        $available = Money::zero();
        $reserved = Money::zero();
        $applied = Money::zero();
        $refunded = Money::zero();

        ExcessLedgerEntryModel::query()
            ->where('excess_balance_id', $excessId)
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->each(function (ExcessLedgerEntryModel $entry) use (
                &$retained,
                &$available,
                &$reserved,
                &$applied,
                &$refunded,
            ): void {
                $amount = new Money((string) $entry->amount);
                match ($entry->entry_type) {
                    ExcessLedgerEntryType::EXCESS_DETECTED => $retained = $retained->add($amount),
                    ExcessLedgerEntryType::MARKED_AS_CREDIT => [
                        $retained = $retained->subtract($amount),
                        $available = $available->add($amount),
                    ],
                    ExcessLedgerEntryType::RESERVED_FOR_REFUND => [
                        $retained = $retained->subtract($amount),
                        $reserved = $reserved->add($amount),
                    ],
                    ExcessLedgerEntryType::CREDIT_APPLIED => [
                        $available = $available->subtract($amount),
                        $applied = $applied->add($amount),
                    ],
                    ExcessLedgerEntryType::REFUND_COMPLETED => [
                        $reserved = $reserved->subtract($amount),
                        $refunded = $refunded->add($amount),
                    ],
                };
            });

        return [
            'retained' => $retained->value(),
            'available' => $available->value(),
            'reserved' => $reserved->value(),
            'applied' => $applied->value(),
            'refunded' => $refunded->value(),
        ];
    }

    public function isConsistent(ExcessBalanceModel $balance): bool
    {
        $rebuilt = $this->rebuild($balance->id);

        return $rebuilt === [
            'retained' => (string) $balance->retained_amount,
            'available' => (string) $balance->available_amount,
            'reserved' => (string) $balance->reserved_refund_amount,
            'applied' => (string) $balance->applied_amount,
            'refunded' => (string) $balance->refunded_amount,
        ];
    }
}
