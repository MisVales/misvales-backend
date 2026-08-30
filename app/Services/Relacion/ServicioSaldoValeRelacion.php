<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\RelacionDistribuidora;
use App\Models\RelacionPartidaDistribuidora;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ServicioSaldoValeRelacion
{
    /** @return array<string, array<string, mixed>> keyed by voucher id */
    public function posiciones(RelacionDistribuidora $relation, bool $asGenerated = false, bool $includeCurrentLateFee = false): array
    {
        $chain = $this->chain($relation);
        $lateFeeUnits = $chain->mapWithKeys(function (RelacionDistribuidora $candidate) use ($relation, $asGenerated, $includeCurrentLateFee): array {
            if ($asGenerated && ! $includeCurrentLateFee && $candidate->id === $relation->id) {
                return [$candidate->id => '0.0000'];
            }
            $fee = DB::table('relation_late_fees')
                ->where('relation_id', $candidate->id)
                ->where('type', 'LATE_FEE')
                ->whereNull('voided_at')
                ->first();
            if ($fee === null) {
                return [$candidate->id => '0.0000'];
            }
            $snapshot = is_string($fee->configuration_snapshot) ? json_decode($fee->configuration_snapshot, true) : (array) $fee->configuration_snapshot;
            $units = max(1, (int) ($snapshot['late_fee_units'] ?? $candidate->partidas()->count()));

            return [$candidate->id => (string) ($snapshot['late_fee_unit_amount'] ?? bcdiv((string) $fee->amount, (string) $units, 4))];
        });
        $items = $chain->flatMap(fn (RelacionDistribuidora $candidate) => $candidate->partidas()
            ->with(['installment.vale', 'sourceInstallment.vale'])
            ->get()
            ->map(fn ($item) => ['relation' => $candidate, 'item' => $item]))
            ->sortBy(fn (array $row) => sprintf('%s|%s|%08d|%08d',
                $row['relation']->cutoff_at?->toIso8601String() ?? '',
                (string) ($row['item']->snapshot['folio'] ?? ''),
                (int) ($row['item']->snapshot['installment'] ?? 0),
                (int) ($row['item']->terminal_sequence ?? 0),
            ))->values();

        $positions = [];
        foreach ($items as $row) {
            /** @var RelacionPartidaDistribuidora $item */
            $item = $row['item'];
            $source = $item->sourceInstallment ?? $item->installment;
            $voucherId = $source?->voucher_id;
            if ($voucherId === null) {
                continue;
            }
            $positions[$voucherId] ??= $this->emptyPosition($voucherId, $item);
            $treatAsGenerated = $asGenerated && $row['relation']->id === $relation->id;
            $components = $this->components($item, $row['relation'], $treatAsGenerated);
            $components['surcharge'] = (string) ($lateFeeUnits[$row['relation']->id] ?? '0.0000');
            foreach ($components as $component => $amount) {
                $positions[$voucherId]['components'][$component] = bcadd($positions[$voucherId]['components'][$component], $amount, 4);
            }
            $positions[$voucherId]['cumulative_forfeited_profit'] = bcadd(
                $positions[$voucherId]['cumulative_forfeited_profit'],
                $this->forfeitedProfit($item, $row['relation'], $treatAsGenerated),
                4,
            );
            $positions[$voucherId]['items'][] = $item;
            if ($item->occurrence_type === 'INSTALLMENT'
                && (int) ($item->snapshot['installment'] ?? 0) === (int) ($item->snapshot['total_installments'] ?? -1)) {
                $positions[$voucherId]['source_voucher_installment_id'] = $item->source_voucher_installment_id ?? $item->voucher_installment_id;
                $positions[$voucherId]['final_snapshot'] = $item->snapshot;
            }
            if ($item->occurrence_type === 'TERMINAL_OVERDUE') {
                $positions[$voucherId]['last_terminal_occurrence'] = $item;
            }
        }

        foreach ($positions as &$position) {
            $position['gross_balance'] = array_reduce($position['components'], fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
            $position['gross_surcharge'] = $position['components']['surcharge'];
        }
        unset($position);
        $this->subtractPayments($chain, $items, $positions);
        foreach ($positions as &$position) {
            $position['balance'] = array_reduce($position['components'], fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
            $position['is_pending'] = bccomp($position['balance'], '0', 4) > 0;
            $position['next_terminal_sequence'] = ((int) ($position['last_terminal_occurrence']?->terminal_sequence ?? 0)) + 1;
        }
        unset($position);

        return $positions;
    }

    /** @return list<array<string,mixed>> */
    public function resumenes(RelacionDistribuidora $relation, bool $includeAssessed = false, bool $includeCurrentLateFee = false): array
    {
        $summaries = [];
        foreach ($this->chain($relation) as $candidate) {
            $positions = $this->posiciones(
                $candidate,
                asGenerated: true,
                includeCurrentLateFee: $includeCurrentLateFee && $candidate->id === $relation->id,
            );
            foreach ($candidate->partidas()->with(['installment.vale', 'sourceInstallment.vale'])->get() as $item) {
                $source = $item->sourceInstallment ?? $item->installment;
                $voucherId = $source?->voucher_id;
                if ($voucherId === null || ! isset($positions[$voucherId])) {
                    continue;
                }
                $position = $positions[$voucherId];
                $snapshot = $item->snapshot;
                $summaries[$voucherId] ??= [
                    'voucher_id' => $voucherId,
                    'folio' => (string) ($snapshot['folio'] ?? ''),
                    'client' => (string) ($snapshot['client'] ?? ''),
                    'product' => (string) ($snapshot['product'] ?? ''),
                    'total_installments' => (int) ($snapshot['total_installments'] ?? 0),
                    'cumulative_misvales_due' => '0.0000',
                    'cumulative_misvales_assessed' => '0.0000',
                    'cumulative_surcharge' => '0.0000',
                    'cumulative_surcharge_assessed' => '0.0000',
                    'cumulative_forfeited_profit' => '0.0000',
                    'occurrences' => [],
                ];
                $occurrence = [
                    'relation_id' => $candidate->id,
                    'relation_item_id' => $item->id,
                    'occurrence_type' => $item->occurrence_type,
                    'installment' => (int) ($snapshot['installment'] ?? 0),
                    'total_installments' => (int) ($snapshot['total_installments'] ?? 0),
                    'terminal_sequence' => $item->terminal_sequence,
                    'cumulative_misvales_due' => $position['balance'],
                    'cumulative_misvales_assessed' => $position['gross_balance'],
                    'cumulative_surcharge' => $position['components']['surcharge'],
                    'cumulative_surcharge_assessed' => $position['gross_surcharge'],
                    'cumulative_forfeited_profit' => $position['cumulative_forfeited_profit'],
                ];
                $summaries[$voucherId]['occurrences'][] = $occurrence;
                $summaries[$voucherId]['cumulative_misvales_due'] = $occurrence['cumulative_misvales_due'];
                $summaries[$voucherId]['cumulative_misvales_assessed'] = $occurrence['cumulative_misvales_assessed'];
                $summaries[$voucherId]['cumulative_surcharge'] = $occurrence['cumulative_surcharge'];
                $summaries[$voucherId]['cumulative_surcharge_assessed'] = $occurrence['cumulative_surcharge_assessed'];
                $summaries[$voucherId]['cumulative_forfeited_profit'] = $occurrence['cumulative_forfeited_profit'];
            }
        }

        $summaries = array_values($summaries);
        if (! $includeAssessed) {
            foreach ($summaries as &$summary) {
                unset($summary['cumulative_misvales_assessed'], $summary['cumulative_surcharge_assessed']);
                foreach ($summary['occurrences'] as &$occurrence) {
                    unset($occurrence['cumulative_misvales_assessed'], $occurrence['cumulative_surcharge_assessed']);
                }
                unset($occurrence);
            }
            unset($summary);
        }

        return $summaries;
    }

    private function chain(RelacionDistribuidora $relation): Collection
    {
        $chain = collect();
        for ($current = $relation; $current !== null; $current = $current->anterior()->first()) {
            $chain->prepend($current);
        }

        return $chain->values();
    }

    private function emptyPosition(string $voucherId, RelacionPartidaDistribuidora $item): array
    {
        return [
            'voucher_id' => $voucherId,
            'source_voucher_installment_id' => $item->source_voucher_installment_id ?? $item->voucher_installment_id,
            'components' => ['surcharge' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => '0.0000'],
            'cumulative_forfeited_profit' => '0.0000',
            'items' => [],
            'last_terminal_occurrence' => null,
            'final_snapshot' => null,
        ];
    }

    /** @return array{interest:string,insurance:string,commission:string,capital:string} */
    private function components(RelacionPartidaDistribuidora $item, RelacionDistribuidora $relation, bool $treatAsGenerated = false): array
    {
        $snapshot = $item->snapshot;
        if ($item->occurrence_type === 'TERMINAL_OVERDUE') {
            return ['interest' => '0.0000', 'insurance' => '0.0000', 'commission' => (string) ($snapshot['terminal_charge'] ?? $item->misvales_amount), 'capital' => '0.0000'];
        }
        $isFinal = (int) ($snapshot['installment'] ?? 0) === (int) ($snapshot['total_installments'] ?? -1);
        $overdue = ! $treatAsGenerated && in_array($relation->financial_status, ['OVERDUE', 'ROLLED_FORWARD'], true);
        $canonical = $overdue && ! $isFinal
            ? (string) ($snapshot['base_payment'] ?? $item->portfolio_amount)
            : (string) ($snapshot['misvales_payment'] ?? $item->misvales_amount);
        $interest = (string) ($snapshot['interest'] ?? '0.0000');
        $insurance = (string) ($snapshot['insurance'] ?? '0.0000');
        $capital = (string) ($snapshot['capital'] ?? '0.0000');

        return [
            'interest' => $interest,
            'insurance' => $insurance,
            'commission' => bcsub($canonical, bcadd(bcadd($interest, $insurance, 4), $capital, 4), 4),
            'capital' => $capital,
        ];
    }

    private function forfeitedProfit(RelacionPartidaDistribuidora $item, RelacionDistribuidora $relation, bool $treatAsGenerated): string
    {
        if ($treatAsGenerated || $item->occurrence_type !== 'INSTALLMENT'
            || ! in_array($relation->financial_status, ['OVERDUE', 'ROLLED_FORWARD'], true)) {
            return '0.0000';
        }
        $snapshot = $item->snapshot;
        if ((int) ($snapshot['installment'] ?? 0) === (int) ($snapshot['total_installments'] ?? -1)) {
            return '0.0000';
        }
        $amount = bcsub(
            (string) ($snapshot['base_payment'] ?? $item->portfolio_amount),
            (string) ($snapshot['misvales_payment'] ?? $item->misvales_amount),
            4,
        );

        return bccomp($amount, '0', 4) > 0 ? $amount : '0.0000';
    }

    private function subtractPayments(Collection $chain, Collection $rows, array &$positions): void
    {
        $itemVoucher = [];
        foreach ($positions as $voucherId => $position) {
            foreach ($position['items'] as $item) {
                $itemVoucher[$item->id] = $voucherId;
            }
        }
        $fieldMap = ['SURCHARGE' => 'surcharge', 'INTEREST' => 'interest', 'INSURANCE' => 'insurance', 'LOAN_COMMISSION' => 'commission', 'CAPITAL' => 'capital'];
        foreach ($chain as $relation) {
            foreach ($relation->pagos()->get() as $payment) {
                $allocatedByComponent = DB::table('payment_allocations')->where('payment_id', $payment->id)->get()->groupBy('component');
                foreach ($fieldMap as $ledgerComponent => $component) {
                    $allocated = '0.0000';
                    foreach ($allocatedByComponent->get($ledgerComponent, collect()) as $allocation) {
                        $voucherId = $itemVoucher[$allocation->relation_item_id] ?? null;
                        if ($voucherId !== null) {
                            $this->subtract($positions[$voucherId]['components'][$component], (string) $allocation->amount);
                        }
                        $allocated = bcadd($allocated, (string) $allocation->amount, 4);
                    }
                    $paymentTotal = (string) $payment->{match ($component) {
                        'surcharge' => 'surcharge_applied',
                        'interest' => 'interest_applied', 'insurance' => 'insurance_applied',
                        'commission' => 'commission_applied', default => 'capital_applied',
                    }};
                    $remaining = bcsub($paymentTotal, $allocated, 4);
                    foreach ($rows as $row) {
                        if (bccomp($remaining, '0', 4) <= 0) {
                            break;
                        }
                        $voucherId = $itemVoucher[$row['item']->id] ?? null;
                        if ($voucherId === null) {
                            continue;
                        }
                        $used = $this->subtract($positions[$voucherId]['components'][$component], $remaining);
                        $remaining = bcsub($remaining, $used, 4);
                    }
                }
            }
        }
    }

    private function subtract(string &$pending, string $available): string
    {
        $used = bccomp($available, $pending, 4) > 0 ? $pending : $available;
        if (bccomp($used, '0', 4) > 0) {
            $pending = bcsub($pending, $used, 4);
        }

        return bccomp($used, '0', 4) > 0 ? $used : '0.0000';
    }
}
