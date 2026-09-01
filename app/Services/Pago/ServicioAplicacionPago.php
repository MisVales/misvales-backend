<?php

namespace App\Services\Pago;

use App\Enums\TipoMovimientoLineaCredito;
use App\Models\ExcedenteDistribuidora;
use App\Models\MovimientoBancario;
use App\Models\MovimientoLineaCredito;
use App\Models\PagoRelacion;
use App\Models\RelacionDistribuidora;
use App\Services\Excedente\AuditorExcedente;
use App\Services\Puntos\ServicioCanjePuntos;
use App\Services\Relacion\ServicioSaldoValeRelacion;
use App\Services\Riesgo\ServicioMorosidadDistribuidora;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ServicioAplicacionPago
{
    public function __construct(
        private readonly AuditorExcedente $auditor,
        private readonly ServicioCanjePuntos $puntos,
        private readonly ServicioMorosidadDistribuidora $morosidad,
        private readonly ServicioSaldoValeRelacion $saldos,
    ) {}

    public function aplicar(MovimientoBancario $movement, RelacionDistribuidora $relation, ?string $targetVoucherId = null): PagoRelacion
    {
        return $this->distribuir($movement, $relation, 'BANK_MOVEMENT', $movement->id, $targetVoucherId ?? $movement->target_voucher_id);
    }

    public function aplicarSaldoFavor(string $amount, CarbonImmutable $paidAt, RelacionDistribuidora $relation, string $surplusId): PagoRelacion
    {
        return $this->distribuir(new MovimientoBancario(['amount' => $amount, 'paid_at' => $paidAt]), $relation, 'CREDIT_BALANCE', $surplusId);
    }

    private function distribuir(MovimientoBancario $movement, RelacionDistribuidora $relation, string $sourceType, string $sourceId, ?string $targetVoucherId = null): PagoRelacion
    {
        return DB::transaction(function () use ($movement, $relation, $sourceType, $sourceId, $targetVoucherId): PagoRelacion {
            $relation = RelacionDistribuidora::whereKey($relation->id)->lockForUpdate()->firstOrFail();
            $balanceBefore = (string) $relation->balance;
            if (PagoRelacion::where('source_type', $sourceType)->where('source_id', $sourceId)->exists()) {
                throw new RuntimeException('PAYMENT_ALREADY_ALLOCATED');
            }

            $ledger = $this->saldos->paymentLedger($relation);
            $eligibleIndexes = [];
            $targetExists = false;
            $targetPending = '0.0000';
            foreach ($ledger as $index => $row) {
                if ($targetVoucherId !== null && $row['voucher_id'] !== $targetVoucherId) {
                    continue;
                }
                $eligibleIndexes[] = $index;
                if ($row['voucher_id'] === $targetVoucherId) {
                    $targetExists = true;
                }
                $targetPending = bcadd(
                    $targetPending,
                    $this->sumComponents($row['pending_components']),
                    4,
                );
            }
            if ($targetVoucherId !== null && ! $targetExists) {
                throw new RuntimeException('PAYMENT_VOUCHER_NOT_IN_RELATION');
            }
            if ($targetVoucherId !== null && bccomp($targetPending, '0', 4) <= 0) {
                throw new RuntimeException('PAYMENT_VOUCHER_HAS_NO_PENDING_BALANCE');
            }

            $available = bccomp($movement->amount, $balanceBefore, 4) > 0 ? $balanceBefore : (string) $movement->amount;
            if (bccomp($available, $targetPending, 4) > 0) {
                $available = $targetPending;
            }
            $applied = $available;
            $voucherBalancesBefore = [];
            foreach ($ledger as $row) {
                if ($row['voucher_id'] === null) {
                    continue;
                }
                $voucherBalancesBefore[$row['voucher_id']] = bcadd(
                    $voucherBalancesBefore[$row['voucher_id']] ?? '0.0000',
                    $this->sumComponents($row['pending_components']),
                    4,
                );
            }
            $totals = ['SURCHARGE' => '0.0000', 'INTEREST' => '0.0000', 'INSURANCE' => '0.0000', 'LOAN_COMMISSION' => '0.0000', 'CAPITAL' => '0.0000'];
            $payment = PagoRelacion::create([
                'relation_id' => $relation->id,
                'bank_movement_id' => $movement->exists ? $movement->id : null,
                'target_voucher_id' => $targetVoucherId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'amount' => $applied,
                'applied_at' => $movement->paid_at,
            ]);

            $affected = [];
            foreach (['SURCHARGE' => 'surcharge', 'INTEREST' => 'interest', 'INSURANCE' => 'insurance', 'LOAN_COMMISSION' => 'commission', 'CAPITAL' => 'capital'] as $component => $ledgerComponent) {
                foreach ($eligibleIndexes as $index) {
                    if (bccomp($available, '0', 4) <= 0) {
                        break 2;
                    }
                    $row = $ledger[$index];
                    $pending = (string) $row['pending_components'][$ledgerComponent];
                    if (bccomp($pending, '0', 4) <= 0) {
                        continue;
                    }
                    $amount = bccomp($available, $pending, 4) > 0 ? $pending : $available;
                    if (bccomp($amount, '0', 4) <= 0) {
                        continue;
                    }

                    if ($row['relation_item_id'] !== null) {
                        DB::table('payment_allocations')->insert([
                            'id' => (string) Str::uuid(),
                            'payment_id' => $payment->id,
                            'relation_item_id' => $row['relation_item_id'],
                            'voucher_id' => $row['voucher_id'],
                            'component' => $component,
                            'amount' => $amount,
                            'created_at' => now(),
                        ]);
                    }
                    $ledger[$index]['pending_components'][$ledgerComponent] = bcsub($pending, $amount, 4);
                    $totals[$component] = bcadd($totals[$component], $amount, 4);
                    $available = bcsub($available, $amount, 4);
                    if ($row['voucher_id'] !== null) {
                        $affected[$row['voucher_id']]['client_id'] ??= $row['client_id'];
                        $affected[$row['voucher_id']]['components'][$ledgerComponent] ??= '0.0000';
                        $affected[$row['voucher_id']]['components'][$ledgerComponent] = bcadd($affected[$row['voucher_id']]['components'][$ledgerComponent], $amount, 4);
                    }
                }
            }
            $line = $relation->distribuidora->lineaCredito()->lockForUpdate()->first();
            $recovered = $totals['CAPITAL'];
            $creditUsedBefore = $line?->used_balance;
            $creditUsedAfter = $creditUsedBefore;
            if ($line && bccomp($recovered, '0', 4) > 0) {
                if (bccomp($recovered, $line->used_balance, 4) > 0) {
                    throw new RuntimeException('CREDIT_LINE_RECOVERY_EXCEEDS_USED_BALANCE');
                }
                $before = $line->used_balance;
                $line->used_balance = bcsub($line->used_balance, $recovered, 4);
                $line->lock_version++;
                $line->save();
                $sequence = ((int) MovimientoLineaCredito::where('credit_line_id', $line->id)->max('sequence')) + 1;
                MovimientoLineaCredito::create(['credit_line_id' => $line->id, 'distributor_id' => $line->distributor_id, 'sequence' => $sequence, 'type' => TipoMovimientoLineaCredito::PAYMENT_RECOVERY, 'amount' => $recovered, 'total_authorized_before' => $line->total_authorized, 'total_authorized_after' => $line->total_authorized, 'used_balance_before' => $before, 'used_balance_after' => $line->used_balance, 'source_type' => 'RELATION_PAYMENT', 'source_id' => $payment->id, 'reason' => 'Recuperación de capital conciliado', 'idempotency_key' => 'relation-payment:'.$payment->id, 'occurred_at' => now()]);
                $creditUsedAfter = $line->used_balance;
            }
            $relation->balance = array_reduce(
                $ledger,
                fn (string $total, array $row): string => bcadd($total, $this->sumComponents($row['pending_components']), 4),
                '0.0000',
            );
            if (bccomp((string) $relation->balance, '0', 4) < 0) {
                $relation->balance = '0.0000';
            }
            $relation->reconciled_total = bcadd($relation->reconciled_total, $applied, 4);
            $newlySettled = bccomp($balanceBefore, '0', 4) > 0 && bccomp($relation->balance, '0', 4) === 0;
            if ($newlySettled) {
                $relation->financial_status = 'SETTLED';
                $relation->settled_at = $movement->paid_at;
                $relation->temporal_classification = $movement->paid_at->lte($relation->advance_period_end)
                    ? 'EARLY'
                    : ($movement->paid_at->lte($relation->payment_deadline_at) ? 'ON_TIME' : 'LATE');
            } elseif (bccomp($relation->balance, '0', 4) > 0) {
                $relation->financial_status = in_array($relation->financial_status, ['OVERDUE', 'ROLLED_FORWARD'], true)
                    || ($relation->payment_deadline_at !== null && $movement->paid_at->gt($relation->payment_deadline_at))
                    ? 'OVERDUE'
                    : 'PARTIALLY_PAID';
            }
            $relation->save();

            $trace = [];
            foreach ($affected as $voucherId => $data) {
                $beforeVoucher = $voucherBalancesBefore[$voucherId] ?? '0.0000';
                $afterVoucher = '0.0000';
                $clientId = $data['client_id'] ?? null;
                foreach ($ledger as $row) {
                    if ($row['voucher_id'] !== $voucherId) {
                        continue;
                    }
                    $afterVoucher = bcadd($afterVoucher, $this->sumComponents($row['pending_components']), 4);
                    $clientId ??= $row['client_id'];
                }
                $covered = $data['components'];
                $covered += ['surcharge' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => '0.0000'];
                $trace[$voucherId] = [
                    'voucher_id' => $voucherId,
                    'client_id' => $clientId,
                    'components_covered' => $covered,
                    'capital_recovered' => $covered['capital'],
                    'balance_before' => $beforeVoucher,
                    'balance_after' => $afterVoucher,
                    'credit_used_before' => $creditUsedBefore,
                    'credit_used_after' => $creditUsedAfter,
                ];
            }
            $payment->update([
                'surcharge_applied' => $totals['SURCHARGE'],
                'interest_applied' => $totals['INTEREST'],
                'insurance_applied' => $totals['INSURANCE'],
                'commission_applied' => $totals['LOAN_COMMISSION'],
                'capital_applied' => $totals['CAPITAL'],
                'line_recovered' => $recovered,
                'trace_snapshot' => array_values($trace),
            ]);
            if ($newlySettled && $relation->temporal_classification === 'EARLY') {
                $this->puntos->acreditarLiquidacionAnticipada($relation);
            }
            if ($newlySettled) {
                $this->morosidad->notificarDeudaRegularizada($relation->fresh('distribuidora'));
            }
            $surplus = bcsub($movement->amount, $applied, 4);
            if ($movement->exists) {
                $movement->update(['relation_id' => $relation->id, 'classification' => bccomp($surplus, '0', 4) > 0 ? 'SURPLUS' : (bccomp($relation->balance, '0', 4) === 0 ? 'SETTLEMENT' : 'PARTIAL_PAYMENT'), 'applied_amount' => $applied, 'surplus_amount' => $surplus]);
            }
            if ($movement->exists && bccomp($surplus, '0', 4) > 0) {
                $excess = ExcedenteDistribuidora::firstOrCreate(
                    ['bank_movement_id' => $movement->id],
                    [
                        'distributor_id' => $relation->distributor_id,
                        'branch_id' => $relation->branch_id,
                        'origin_relation_id' => $relation->id,
                        'original_amount' => $surplus,
                        'available_amount' => $surplus,
                        'status' => 'PENDING_DECISION',
                    ],
                );
                if ($excess->wasRecentlyCreated) {
                    $payload = [
                        'distributor_id' => $relation->distributor_id,
                        'branch_id' => $relation->branch_id,
                        'relation_id' => $relation->id,
                        'bank_movement_id' => $movement->id,
                        'amount' => $surplus,
                        'available_amount' => $surplus,
                        'status' => 'PENDING_DECISION',
                    ];
                    $this->auditor->registrar('PAYMENT_SURPLUS_DETECTED', 'distributor_surplus', $excess->id, $movement->reconciled_by, $relation->branch_id, $payload);
                    $this->auditor->registrar('EXCESS_CREATED', 'distributor_surplus', $excess->id, $movement->reconciled_by, $relation->branch_id, $payload);
                }
            }

            return $payment->fresh();
        });
    }

    /** @param array<string,string> $components */
    private function sumComponents(array $components): string
    {
        return array_reduce(
            $components,
            static fn (string $total, string $amount): string => bcadd($total, (string) $amount, 4),
            '0.0000',
        );
    }
}
