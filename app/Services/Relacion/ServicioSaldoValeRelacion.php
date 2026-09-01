<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\RelacionDistribuidora;
use App\Models\RelacionPartidaDistribuidora;
use App\Models\Vale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ServicioSaldoValeRelacion
{
    /**
     * Saldo exigible actual de un vale, tomando la cadena de relaciones y sus
     * conciliaciones. Un vale sin relación todavía conserva como pendiente su
     * total generado.
     */
    public function saldoPendienteVale(Vale $voucher): string
    {
        $relation = RelacionDistribuidora::query()
            ->where('distributor_id', $voucher->distributor_id)
            ->where('branch_id', $voucher->branch_id)
            ->latest('cutoff_at')
            ->first();

        if ($relation !== null) {
            $position = $relation === null ? null : $this->posiciones($relation)[$voucher->id] ?? null;

            return (string) ($position['balance'] ?? '0.0000');
        }

        if (in_array($voucher->status?->value, ['REJECTED', 'CANCELLED'], true)) {
            return '0.0000';
        }

        return (string) ($voucher->misvales_total ?? '0.0000');
    }

    /** @return array<string, array<string, mixed>> keyed by voucher id */
    public function posiciones(RelacionDistribuidora $relation, bool $asGenerated = false, bool $includeCurrentLateFee = false): array
    {
        $chain = $this->chain($relation);
        $lateFees = $chain->mapWithKeys(fn (RelacionDistribuidora $candidate): array => [
            $candidate->id => $this->lateFeeAmounts($candidate, $relation, $asGenerated, $includeCurrentLateFee),
        ]);
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
            $components['surcharge'] = (string) (($lateFees[$row['relation']->id] ?? [])[$item->id] ?? '0.0000');
            $bucket = in_array($row['relation']->financial_status, ['OVERDUE', 'ROLLED_FORWARD'], true)
                ? 'overdue_components'
                : 'current_components';
            $positions[$voucherId]['item_buckets'][$item->id] = $bucket;
            foreach ($components as $component => $amount) {
                $positions[$voucherId]['components'][$component] = bcadd($positions[$voucherId]['components'][$component], $amount, 4);
                $positions[$voucherId]['gross_components'][$component] = bcadd($positions[$voucherId]['gross_components'][$component], $amount, 4);
                $positions[$voucherId][$bucket][$component] = bcadd($positions[$voucherId][$bucket][$component], $amount, 4);
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
            $rawBalance = array_reduce($position['gross_components'], fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
            $rounding = bcsub($this->redondearTotal($rawBalance), $rawBalance, 4);
            if (bccomp($rounding, '0', 4) !== 0 && $position['items'] !== []) {
                $lastItem = end($position['items']);
                $bucket = $position['item_buckets'][$lastItem->id] ?? 'current_components';
                $position['gross_components']['commission'] = bcadd($position['gross_components']['commission'], $rounding, 4);
                $position['components']['commission'] = bcadd($position['components']['commission'], $rounding, 4);
                $position[$bucket]['commission'] = bcadd($position[$bucket]['commission'], $rounding, 4);
            }
            $position['gross_balance'] = $this->sumComponents($position['gross_components']);
            $position['gross_surcharge'] = $position['gross_components']['surcharge'];
        }
        unset($position);
        $this->subtractPayments($chain, $items, $positions);
        foreach ($positions as &$position) {
            $position['paid_components'] = [];
            foreach ($position['gross_components'] as $component => $amount) {
                $position['paid_components'][$component] = bcsub($amount, $position['components'][$component], 4);
            }
            $position['balance'] = array_reduce($position['components'], fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
            $position['current_balance'] = array_reduce($position['current_components'], fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
            $position['overdue_balance'] = array_reduce($position['overdue_components'], fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
            $position['is_pending'] = bccomp($position['balance'], '0', 4) > 0;
            $position['next_terminal_sequence'] = ((int) ($position['last_terminal_occurrence']?->terminal_sequence ?? 0)) + 1;
        }
        unset($position);

        return $positions;
    }

    /**
     * Devuelve el ledger por partida que queda disponible para aplicar un pago.
     * Incluye partidas de relaciones anteriores para que un pago dirigido a un
     * vale no termine afectando a otro vale de la relación vigente.
     *
     * @return list<array<string,mixed>>
     */
    public function paymentLedger(RelacionDistribuidora $relation, bool $asGenerated = false, bool $includeCurrentLateFee = true): array
    {
        $chain = $this->chain($relation);
        $rows = [];

        foreach ($chain as $candidate) {
            $lateFees = $this->lateFeeAmounts($candidate, $relation, $asGenerated, $includeCurrentLateFee);
            foreach ($candidate->partidas()->with(['installment.vale', 'sourceInstallment.vale'])->get() as $item) {
                $source = $item->sourceInstallment ?? $item->installment;
                $voucherId = $source?->voucher_id;
                if ($voucherId === null) {
                    continue;
                }

                $components = $this->components($item, $candidate, $asGenerated && $candidate->id === $relation->id);
                $components['surcharge'] = (string) ($lateFees[$item->id] ?? '0.0000');
                foreach ($components as $component => $amount) {
                    if (bccomp((string) $amount, '0', 4) < 0) {
                        $components[$component] = '0.0000';
                    }
                }

                $rows[] = [
                    'relation_id' => $candidate->id,
                    'relation_item_id' => $item->id,
                    'voucher_id' => $voucherId,
                    'client_id' => $source?->vale?->client_id,
                    'item' => $item,
                    'gross_components' => $components,
                    'pending_components' => $components,
                ];
            }
        }

        $hasPriorRows = collect($rows)->contains(fn (array $row): bool => $row['relation_id'] !== $relation->id);
        $carried = [
            'surcharge' => (string) ($relation->carried_surcharge ?: $relation->surcharge_total),
            'interest' => (string) $relation->carried_interest,
            'insurance' => (string) $relation->carried_insurance,
            'commission' => (string) $relation->carried_commission,
            'capital' => (string) $relation->carried_capital,
        ];
        $carriedTotal = array_reduce($carried, fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
        if (! $hasPriorRows && bccomp($carriedTotal, '0', 4) > 0) {
            $rows[] = [
                'relation_id' => $relation->id,
                'relation_item_id' => null,
                'voucher_id' => null,
                'client_id' => null,
                'item' => null,
                'gross_components' => $carried,
                'pending_components' => $carried,
            ];
        }

        if ($rows === [] && bccomp((string) $relation->balance, '0', 4) > 0) {
            $rows[] = [
                'relation_id' => $relation->id,
                'relation_item_id' => null,
                'voucher_id' => null,
                'client_id' => null,
                'item' => null,
                'gross_components' => ['surcharge' => (string) $relation->surcharge_total, 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => (string) $relation->balance],
                'pending_components' => ['surcharge' => (string) $relation->surcharge_total, 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => (string) $relation->balance],
            ];
        }

        $this->ajustarRedondeoLedger($rows);
        $this->subtractLedgerPayments($chain, $rows);

        return $rows;
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
                    'amount_owed' => '0.0000',
                    'amount_paid' => '0.0000',
                    'capital_owed' => '0.0000',
                    'capital_paid' => '0.0000',
                    'capital_pending' => '0.0000',
                    'interest_pending' => '0.0000',
                    'insurance_pending' => '0.0000',
                    'commission_pending' => '0.0000',
                    'current_amount' => '0.0000',
                    'overdue_amount' => '0.0000',
                    'accumulated_surcharges' => '0.0000',
                    'pending_balance' => '0.0000',
                    'is_settled' => false,
                    'financial_status' => 'PENDING',
                    'current_installment' => null,
                    'occurrences' => [],
                ];
                $summary = &$summaries[$voucherId];
                $summary['amount_owed'] = $position['gross_balance'];
                $summary['amount_paid'] = array_reduce($position['paid_components'], fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
                $summary['capital_owed'] = $position['gross_components']['capital'];
                $summary['capital_paid'] = $position['paid_components']['capital'];
                $summary['capital_pending'] = $position['components']['capital'];
                $summary['interest_pending'] = $position['components']['interest'];
                $summary['insurance_pending'] = $position['components']['insurance'];
                $summary['commission_pending'] = $position['components']['commission'];
                $summary['current_amount'] = $position['current_balance'];
                $summary['overdue_amount'] = $position['overdue_balance'];
                $summary['accumulated_surcharges'] = $position['gross_surcharge'];
                $summary['pending_balance'] = $position['balance'];
                $summary['is_settled'] = ! $position['is_pending'];
                $summary['financial_status'] = $position['is_pending'] && in_array($candidate->financial_status, ['OVERDUE', 'ROLLED_FORWARD'], true) ? 'OVERDUE' : ($position['is_pending'] ? 'PENDING' : 'SETTLED');
                $summary['current_installment'] = (int) ($snapshot['installment'] ?? 0);
                unset($summary);
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

        $finalPositions = $this->posiciones(
            $relation,
            asGenerated: true,
            includeCurrentLateFee: $includeCurrentLateFee,
        );
        foreach ($finalPositions as $voucherId => $position) {
            if (! isset($summaries[$voucherId])) {
                continue;
            }
            $summaries[$voucherId]['amount_owed'] = $position['gross_balance'];
            $summaries[$voucherId]['amount_paid'] = array_reduce($position['paid_components'], fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
            $summaries[$voucherId]['capital_owed'] = $position['gross_components']['capital'];
            $summaries[$voucherId]['capital_paid'] = $position['paid_components']['capital'];
            $summaries[$voucherId]['capital_pending'] = $position['components']['capital'];
            $summaries[$voucherId]['interest_pending'] = $position['components']['interest'];
            $summaries[$voucherId]['insurance_pending'] = $position['components']['insurance'];
            $summaries[$voucherId]['commission_pending'] = $position['components']['commission'];
            $summaries[$voucherId]['current_amount'] = $position['current_balance'];
            $summaries[$voucherId]['overdue_amount'] = $position['overdue_balance'];
            $summaries[$voucherId]['accumulated_surcharges'] = $position['gross_surcharge'];
            $summaries[$voucherId]['pending_balance'] = $position['balance'];
            $summaries[$voucherId]['is_settled'] = ! $position['is_pending'];
            $summaries[$voucherId]['financial_status'] = $position['is_pending'] && bccomp($position['overdue_balance'], '0', 4) > 0 ? 'OVERDUE' : ($position['is_pending'] ? 'PENDING' : 'SETTLED');
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
            'gross_components' => ['surcharge' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => '0.0000'],
            'current_components' => ['surcharge' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => '0.0000'],
            'overdue_components' => ['surcharge' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => '0.0000'],
            'item_buckets' => [],
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

    /** @return array<string,string> keyed by relation item id */
    private function lateFeeAmounts(RelacionDistribuidora $candidate, RelacionDistribuidora $relation, bool $asGenerated, bool $includeCurrentLateFee): array
    {
        if ($asGenerated && ! $includeCurrentLateFee && $candidate->id === $relation->id) {
            return [];
        }

        $fee = DB::table('relation_late_fees')
            ->where('relation_id', $candidate->id)
            ->where('type', 'LATE_FEE')
            ->whereNull('voided_at')
            ->first();
        if ($fee === null) {
            return [];
        }

        $snapshot = is_string($fee->configuration_snapshot) ? json_decode($fee->configuration_snapshot, true) : (array) $fee->configuration_snapshot;
        $itemFees = is_array($snapshot['late_fee_items'] ?? null) ? $snapshot['late_fee_items'] : [];
        if ($itemFees !== []) {
            return collect($itemFees)->mapWithKeys(fn ($amount, $itemId): array => [(string) $itemId => bcadd((string) $amount, '0', 4)])->all();
        }

        $itemIds = $candidate->partidas()->orderBy('created_at')->orderBy('id')->pluck('id')->all();
        $units = max(1, (int) ($snapshot['late_fee_units'] ?? count($itemIds)));
        $unitAmount = (string) ($snapshot['late_fee_unit_amount'] ?? bcdiv((string) $fee->amount, (string) $units, 4));

        return collect($itemIds)->mapWithKeys(fn (string $itemId): array => [$itemId => $unitAmount])->all();
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
        $itemBucket = [];
        foreach ($positions as $voucherId => $position) {
            foreach ($position['items'] as $item) {
                $itemVoucher[$item->id] = $voucherId;
                $itemBucket[$item->id] = $position['item_buckets'][$item->id] ?? 'current_components';
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
                            $bucket = $itemBucket[$allocation->relation_item_id] ?? 'current_components';
                            $this->subtract($positions[$voucherId][$bucket][$component], (string) $allocation->amount);
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
                        $bucket = $itemBucket[$row['item']->id] ?? 'current_components';
                        $this->subtract($positions[$voucherId][$bucket][$component], $used);
                        $remaining = bcsub($remaining, $used, 4);
                    }
                }
            }
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function subtractLedgerPayments(Collection $chain, array &$rows): void
    {
        $rowByItem = [];
        foreach ($rows as $index => $row) {
            if ($row['relation_item_id'] !== null) {
                $rowByItem[$row['relation_item_id']] = $index;
            }
        }
        $fieldMap = ['SURCHARGE' => 'surcharge', 'INTEREST' => 'interest', 'INSURANCE' => 'insurance', 'LOAN_COMMISSION' => 'commission', 'CAPITAL' => 'capital'];

        foreach ($chain as $relation) {
            foreach ($relation->pagos()->get() as $payment) {
                $allocatedByComponent = DB::table('payment_allocations')->where('payment_id', $payment->id)->get()->groupBy('component');
                foreach ($fieldMap as $ledgerComponent => $component) {
                    $allocated = '0.0000';
                    foreach ($allocatedByComponent->get($ledgerComponent, collect()) as $allocation) {
                        $index = $rowByItem[$allocation->relation_item_id] ?? null;
                        if ($index !== null) {
                            $row = $rows[$index];
                            $this->subtract($row['pending_components'][$component], (string) $allocation->amount);
                            $rows[$index] = $row;
                        }
                        $allocated = bcadd($allocated, (string) $allocation->amount, 4);
                    }

                    $paymentTotal = (string) $payment->{match ($component) {
                        'surcharge' => 'surcharge_applied',
                        'interest' => 'interest_applied',
                        'insurance' => 'insurance_applied',
                        'commission' => 'commission_applied',
                        default => 'capital_applied',
                    }};
                    $remaining = bcsub($paymentTotal, $allocated, 4);
                    foreach ($rows as $index => $row) {
                        if (bccomp($remaining, '0', 4) <= 0) {
                            break;
                        }
                        $used = $this->subtract($row['pending_components'][$component], $remaining);
                        $remaining = bcsub($remaining, $used, 4);
                        $rows[$index] = $row;
                    }
                }
            }
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function ajustarRedondeoLedger(array &$rows): void
    {
        collect($rows)->filter(fn (array $row): bool => $row['voucher_id'] !== null)
            ->groupBy('voucher_id')
            ->each(function (Collection $voucherRows) use (&$rows): void {
                $raw = $voucherRows->reduce(
                    fn (string $sum, array $row): string => bcadd($sum, $this->sumComponents($row['gross_components']), 4),
                    '0.0000',
                );
                $rounding = bcsub($this->redondearTotal($raw), $raw, 4);
                if (bccomp($rounding, '0', 4) === 0) {
                    return;
                }
                $index = $voucherRows->keys()->last();
                $rows[$index]['gross_components']['commission'] = bcadd($rows[$index]['gross_components']['commission'], $rounding, 4);
                $rows[$index]['pending_components']['commission'] = bcadd($rows[$index]['pending_components']['commission'], $rounding, 4);
            });
    }

    /** @param array<string,string> $components */
    private function sumComponents(array $components): string
    {
        return array_reduce($components, static fn (string $sum, string $amount): string => bcadd($sum, (string) $amount, 4), '0.0000');
    }

    private function redondearTotal(string $amount): string
    {
        return bcadd($amount, '0.5', 0).'.0000';
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
