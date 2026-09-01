<?php

namespace App\Services\Relacion;

use App\Models\Distribuidora;
use App\Models\RelacionDistribuidora;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use RuntimeException;

final class ServicioPdfEstadoCuenta
{
    public function __construct(private readonly ServicioSaldoValeRelacion $saldos) {}

    /** @return array<string, mixed> */
    public function preparar(Distribuidora $distributor): array
    {
        $distributor->loadMissing(['usuario', 'sucursal', 'lineaCredito', 'coordinadorVigente.coordinator']);
        $relations = $distributor->relaciones()
            ->with(['partidas.installment.vale', 'partidas.sourceInstallment.vale'])
            ->oldest('cutoff_at')
            ->get();
        $latestRelation = $relations->last();
        $movementRows = collect($this->movementRows($relations, $latestRelation));
        $relationIndexes = $relations->pluck('id')->flip();
        $cuts = $relations->values()->map(function (RelacionDistribuidora $relation, int $index) use ($latestRelation, $movementRows, $relationIndexes): array {
            $includeCurrentLateFee = $latestRelation !== null && $relation->id === $latestRelation->id;
            $pendingByVoucher = collect($this->saldos->resumenes(
                $relation,
                includeCurrentLateFee: $includeCurrentLateFee,
            ))->mapWithKeys(fn (array $summary): array => [
                $summary['voucher_id'] => (string) ($summary['pending_balance'] ?? $summary['cumulative_misvales_due']),
            ]);
            $rows = $movementRows
                ->filter(fn (array $row): bool => (int) $relationIndexes[$row['relation_id']] <= $index)
                ->values();
            $rows = $this->applyVoucherPayments($rows, $pendingByVoucher, $relation);
            $rows = $this->financializeRows($rows, $pendingByVoucher);
            $groups = $rows
                ->groupBy('client_key')
                ->map(function (Collection $rows) use ($pendingByVoucher): array {
                    $first = $rows->first();

                    return [
                        'client' => $first['client'], 'rows' => $rows->values()->all(),
                        'subtotal' => $this->snapshotTotals($rows, $pendingByVoucher),
                    ];
                })->values();

            return [
                'number' => $index + 1, 'id' => $relation->id, 'reference' => $relation->payment_reference,
                'cutoff_at' => $relation->cutoff_at, 'deadline_at' => $relation->payment_deadline_at,
                'status' => $this->status($relation->financial_status), 'clients' => $groups->all(),
                'subtotal' => $this->snapshotTotals($rows, $pendingByVoucher),
            ];
        });
        $general = $cuts->last()['subtotal'] ?? $this->emptyTotals();
        $general['outstanding'] = $general['misvales_payment'];
        $snapshot = $latestRelation?->header_snapshot ?? [];

        return [
            'distributor' => [
                'name' => (string) ($snapshot['name'] ?? $distributor->usuario?->name ?? 'Sin nombre'),
                'number' => (string) ($snapshot['number'] ?? $distributor->distributor_number),
                'branch' => (string) ($snapshot['branch'] ?? $distributor->sucursal?->name ?? 'Sin sucursal'),
                'coordinator' => (string) ($snapshot['coordinator'] ?? $distributor->coordinadorVigente?->coordinator?->name ?? 'Sin coordinador'),
            ],
            'latest' => $latestRelation === null ? null : [
                'reference' => $latestRelation->payment_reference, 'cutoff_at' => $latestRelation->cutoff_at,
                'deadline_at' => $latestRelation->payment_deadline_at, 'status' => $this->status($latestRelation->financial_status),
            ],
            'credit' => [
                'authorized' => (string) ($distributor->lineaCredito?->total_authorized ?? '0.0000'),
                'used' => (string) ($distributor->lineaCredito?->used_balance ?? '0.0000'),
                'available' => (string) ($distributor->lineaCredito?->saldoDisponible() ?? '0.0000'),
            ],
            'cuts' => $cuts->all(), 'general' => $general,
        ];
    }

    public function generar(Distribuidora $distributor): string
    {
        $logoPath = storage_path('app/public/branding/misvales.jpg');
        if (! is_file($logoPath)) {
            throw new RuntimeException('ACCOUNT_STATEMENT_PDF_LOGO_MISSING');
        }
        $html = view('relations.account-statement', [
            'statement' => $this->preparar($distributor),
            'logo' => 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)),
        ])->render();
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    /** @return list<array<string, mixed>> */
    private function movementRows(Collection $relations, ?RelacionDistribuidora $latestRelation): array
    {
        if ($latestRelation === null) {
            return [];
        }
        $items = $relations->flatMap->partidas->keyBy('id');
        $rows = [];
        foreach ($this->saldos->resumenes(
            $latestRelation,
            includeAssessed: true,
            includeCurrentLateFee: true,
        ) as $summary) {
            $previousAssessed = $previousSurcharge = '0.0000';
            foreach ($summary['occurrences'] as $occurrence) {
                $item = $items->get($occurrence['relation_item_id']);
                if ($item === null) {
                    continue;
                }
                $assessed = (string) ($occurrence['cumulative_misvales_assessed'] ?? $occurrence['cumulative_misvales_due']);
                $surcharge = (string) ($occurrence['cumulative_surcharge_assessed'] ?? $occurrence['cumulative_surcharge']);
                $movementTotal = $this->nonNegative(bcsub($assessed, $previousAssessed, 4));
                $surchargeMovement = $this->nonNegative(bcsub($surcharge, $previousSurcharge, 4));
                $misvalesMovement = $this->nonNegative(bcsub($movementTotal, $surchargeMovement, 4));
                $clientCollection = (string) $item->portfolio_amount;
                $snapshot = $item->snapshot;
                $sequence = (int) ($occurrence['terminal_sequence'] ?? 0);
                $isTerminal = $occurrence['occurrence_type'] === 'TERMINAL_OVERDUE';
                $sourceRelation = $relations->firstWhere('id', $occurrence['relation_id']);
                $source = $item->sourceInstallment ?? $item->installment;

                $rows[] = [
                    'relation_id' => $occurrence['relation_id'],
                    'relation_item_id' => $occurrence['relation_item_id'],
                    'source_status' => $sourceRelation?->financial_status,
                    'source_deadline' => $sourceRelation?->payment_deadline_at,
                    'voucher_id' => $summary['voucher_id'],
                    'client_key' => (string) ($source?->vale?->client_id ?? mb_strtolower(trim((string) $summary['client']))),
                    'client' => (string) ($summary['client'] ?: ($snapshot['client'] ?? 'Sin cliente')),
                    'folio' => (string) ($summary['folio'] ?: ($snapshot['folio'] ?? 'Sin folio')),
                    'product' => (string) ($summary['product'] ?: ($snapshot['product'] ?? 'Sin producto')),
                    'installment' => $isTerminal
                        ? '*'.$occurrence['installment'].'/'.$occurrence['total_installments'].($sequence > 1 ? ' sec. '.$sequence : '')
                        : $occurrence['installment'].'/'.$occurrence['total_installments'],
                    'commission_percentage' => (string) ($snapshot['distributor_profit_percentage'] ?? '0.000000'),
                    'client_collection' => $clientCollection, 'commission' => '0.0000',
                    'misvales_payment' => $misvalesMovement, 'surcharge' => $surchargeMovement,
                    'movement_total' => $movementTotal, 'cumulative_total' => (string) $occurrence['cumulative_misvales_due'],
                    'outstanding' => (string) $occurrence['cumulative_misvales_due'],
                ];
                $previousAssessed = $assessed;
                $previousSurcharge = $surcharge;
            }
        }

        return $rows;
    }

    /** @param Collection<string, string> $pendingByVoucher */
    private function applyVoucherPayments(Collection $rows, Collection $pendingByVoucher, RelacionDistribuidora $relation): Collection
    {
        return $rows->groupBy('voucher_id')->flatMap(function (Collection $voucherRows, string $voucherId) use ($pendingByVoucher, $relation): array {
            $gross = $voucherRows->reduce(
                fn (string $sum, array $row): string => bcadd($sum, $row['movement_total'], 4),
                '0.0000',
            );
            $remainingPaid = $this->nonNegative(bcsub($gross, (string) ($pendingByVoucher[$voucherId] ?? $gross), 4));

            return $voucherRows->map(function (array $row) use (&$remainingPaid, $relation): array {
                $paid = $this->minimum($remainingPaid, $row['movement_total']);
                $remainingPaid = bcsub($remainingPaid, $paid, 4);
                $row['paid'] = $paid;
                $row['pending'] = $this->nonNegative(bcsub($row['movement_total'], $paid, 4));
                $row['status'] = $this->itemStatus($row, $relation, $paid);

                return $row;
            })->all();
        })->values();
    }

    /** @param Collection<string, string> $pendingByVoucher */
    private function financializeRows(Collection $rows, Collection $pendingByVoucher): Collection
    {
        return $rows->groupBy('voucher_id')->flatMap(function (Collection $voucherRows, string $voucherId) use ($pendingByVoucher): array {
            $lastIndex = $voucherRows->keys()->last();

            return $voucherRows->map(function (array $row, int $index) use ($lastIndex, $pendingByVoucher, $voucherId): array {
                $misvalesDue = $index === $lastIndex
                    ? (string) ($pendingByVoucher[$voucherId] ?? $row['cumulative_total'])
                    : (string) $row['cumulative_total'];
                $commission = bcmul($misvalesDue, (string) $row['commission_percentage'], 4);
                $row['misvales_payment'] = $misvalesDue;
                $row['commission'] = $commission;
                $row['client_collection'] = bcadd($misvalesDue, $commission, 4);
                $row['cumulative_total'] = $misvalesDue;

                return $row;
            })->all();
        })->values();
    }

    /** @param Collection<string, string> $pendingByVoucher */
    private function snapshotTotals(Collection $rows, Collection $pendingByVoucher): array
    {
        $totals = $this->emptyTotals();
        $latestRows = $rows->groupBy('voucher_id')->map(fn (Collection $voucherRows): array => $voucherRows->last());
        foreach (['client_collection', 'commission', 'misvales_payment'] as $field) {
            $totals[$field] = $latestRows->reduce(
                fn (string $sum, array $row): string => bcadd($sum, (string) $row[$field], 4),
                '0.0000',
            );
        }
        $totals['surcharge'] = $rows->reduce(
            fn (string $sum, array $row): string => bcadd($sum, (string) $row['surcharge'], 4),
            '0.0000',
        );
        $totals['movement_total'] = $rows->reduce(
            fn (string $sum, array $row): string => bcadd($sum, (string) $row['movement_total'], 4),
            '0.0000',
        );
        $totals['paid'] = $this->nonNegative(bcsub($totals['movement_total'], $totals['misvales_payment'], 4));
        $totals['total'] = $totals['misvales_payment'];

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'client_collection' => '0.0000', 'commission' => '0.0000',
            'misvales_payment' => '0.0000', 'surcharge' => '0.0000',
            'movement_total' => '0.0000', 'paid' => '0.0000', 'total' => '0.0000',
        ];
    }

    private function itemStatus(array $row, RelacionDistribuidora $snapshotRelation, string $paid): string
    {
        if (bccomp($row['movement_total'], '0', 4) > 0 && bccomp($paid, $row['movement_total'], 4) >= 0) {
            return 'Liquidada';
        }
        if (bccomp($paid, '0', 4) > 0) {
            return 'Abono';
        }
        if ($row['source_status'] === 'SETTLED') {
            return 'Liquidada';
        }
        if ($row['source_deadline'] !== null && (
            in_array($row['source_status'], ['OVERDUE', 'ROLLED_FORWARD'], true)
            || $row['source_deadline']->lt($snapshotRelation->cutoff_at)
        )) {
            return 'Vencida';
        }

        return 'Pendiente';
    }

    private function minimum(string $left, string $right): string
    {
        return bccomp($left, $right, 4) > 0 ? $right : $left;
    }

    private function nonNegative(string $amount): string
    {
        return bccomp($amount, '0', 4) > 0 ? $amount : '0.0000';
    }

    private function status(string $status): string
    {
        return match ($status) {
            'SETTLED' => 'Liquidada', 'PARTIALLY_PAID' => 'Con abonos', 'OVERDUE' => 'Vencida',
            'ROLLED_FORWARD' => 'Trasladada', 'PENDING' => 'Pendiente', default => 'En seguimiento',
        };
    }
}
