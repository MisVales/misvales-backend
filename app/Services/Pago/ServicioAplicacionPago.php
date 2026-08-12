<?php

namespace App\Services\Pago;

use App\Enums\TipoMovimientoLineaCredito;
use App\Models\MovimientoBancario;
use App\Models\MovimientoLineaCredito;
use App\Models\PagoRelacion;
use App\Models\RelacionDistribuidora;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ServicioAplicacionPago
{
    public function aplicar(MovimientoBancario $movement, RelacionDistribuidora $relation): PagoRelacion
    {
        return DB::transaction(function () use ($movement, $relation): PagoRelacion {
            $relation = RelacionDistribuidora::whereKey($relation->id)->lockForUpdate()->firstOrFail();
            if (PagoRelacion::where('bank_movement_id', $movement->id)->exists()) {
                throw new RuntimeException('PAYMENT_ALREADY_ALLOCATED');
            }
            $available = bccomp($movement->amount, $relation->balance, 4) > 0 ? $relation->balance : $movement->amount;
            $applied = $available;
            $totals = ['SURCHARGE' => '0.0000', 'INTEREST' => '0.0000', 'INSURANCE' => '0.0000', 'LOAN_COMMISSION' => '0.0000', 'CAPITAL' => '0.0000'];
            $payment = PagoRelacion::create(['relation_id' => $relation->id, 'bank_movement_id' => $movement->id, 'amount' => $applied, 'applied_at' => $movement->paid_at]);
            $surchargePaid = (string) PagoRelacion::where('relation_id', $relation->id)->whereKeyNot($payment->id)->sum('surcharge_applied');
            $surchargePending = bcsub($relation->surcharge_total, $surchargePaid, 4);
            if (bccomp($surchargePending, '0', 4) > 0) {
                $totals['SURCHARGE'] = bccomp($available, $surchargePending, 4) > 0 ? $surchargePending : $available;
                $available = bcsub($available, $totals['SURCHARGE'], 4);
            }
            $items = $relation->partidas()->with('installment')->get()->sortBy(fn ($i) => sprintf('%s|%s|%05d', $i->installment?->due_at?->toIso8601String() ?? '9999', $i->snapshot['folio'], $i->snapshot['installment']));
            foreach (['INTEREST' => 'interest', 'INSURANCE' => 'insurance', 'LOAN_COMMISSION' => 'loan_commission', 'CAPITAL' => 'capital'] as $component => $field) {
                foreach ($items as $item) {
                    if (bccomp($available, '0', 4) <= 0) {
                        break 2;
                    }$paid = (string) DB::table('payment_allocations')->where('relation_item_id', $item->id)->where('component', $component)->sum('amount');
                    $pending = bcsub((string) $item->snapshot[$field], $paid, 4);
                    $amount = bccomp($available, $pending, 4) > 0 ? $pending : $available;
                    if (bccomp($amount, '0', 4) <= 0) {
                        continue;
                    }DB::table('payment_allocations')->insert(['id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'relation_item_id' => $item->id, 'component' => $component, 'amount' => $amount, 'created_at' => now()]);
                    $totals[$component] = bcadd($totals[$component], $amount, 4);
                    $available = bcsub($available, $amount, 4);
                }
            }
            $line = $relation->distribuidora->lineaCredito()->lockForUpdate()->first();
            $recovered = $totals['CAPITAL'];
            if ($line && bccomp($recovered, '0', 4) > 0) {
                $recovered = bccomp($recovered, $line->used_balance, 4) > 0 ? $line->used_balance : $recovered;
                $before = $line->used_balance;
                $line->used_balance = bcsub($line->used_balance, $recovered, 4);
                $line->lock_version++;
                $line->save();
                $sequence = ((int) MovimientoLineaCredito::where('credit_line_id', $line->id)->max('sequence')) + 1;
                MovimientoLineaCredito::create(['credit_line_id' => $line->id, 'distributor_id' => $line->distributor_id, 'sequence' => $sequence, 'type' => TipoMovimientoLineaCredito::PAYMENT_RECOVERY, 'amount' => $recovered, 'total_authorized_before' => $line->total_authorized, 'total_authorized_after' => $line->total_authorized, 'used_balance_before' => $before, 'used_balance_after' => $line->used_balance, 'source_type' => 'RELATION_PAYMENT', 'source_id' => $payment->id, 'reason' => 'Recuperación de capital conciliado', 'idempotency_key' => 'relation-payment:'.$payment->id, 'occurred_at' => now()]);
            }
            $relation->balance = bcsub($relation->balance, $applied, 4);
            $relation->reconciled_total = bcadd($relation->reconciled_total, $applied, 4);
            if (bccomp($relation->balance, '0', 4) === 0) {
                $relation->financial_status = 'SETTLED';
                $relation->settled_at = $movement->paid_at;
                $relation->temporal_classification = $movement->paid_at->lt($relation->advance_period_end) ? 'EARLY' : ($movement->paid_at->lte($relation->payment_deadline_at) ? 'ON_TIME' : 'LATE');
            } else {
                $relation->financial_status = 'PARTIALLY_PAID';
            }$relation->save();
            $payment->update(['surcharge_applied' => $totals['SURCHARGE'], 'interest_applied' => $totals['INTEREST'], 'insurance_applied' => $totals['INSURANCE'], 'commission_applied' => $totals['LOAN_COMMISSION'], 'capital_applied' => $totals['CAPITAL'], 'line_recovered' => $recovered]);
            $surplus = bcsub($movement->amount, $applied, 4);
            $movement->update(['relation_id' => $relation->id, 'classification' => bccomp($surplus, '0', 4) > 0 ? 'SURPLUS' : (bccomp($relation->balance, '0', 4) === 0 ? 'SETTLEMENT' : 'PARTIAL_PAYMENT'), 'applied_amount' => $applied, 'surplus_amount' => $surplus]);

            return $payment->fresh();
        });
    }
}
