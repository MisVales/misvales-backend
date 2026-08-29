<?php

namespace App\Services\Relacion;

use App\Models\AuditLog;
use App\Models\ParcialidadVale;
use App\Models\RelacionDistribuidora;
use App\Notifications\NotificacionEventoDominio;
use App\Services\Excedente\ServicioExcedente;
use App\Services\Vale\ServicioCalendarioParcialidadesVale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ServicioGeneracionRelacion
{
    public function __construct(
        private readonly ServicioExcedente $surpluses,
        private readonly ServicioConfiguracionRelacion $configuracion,
        private readonly ServicioCalendarioParcialidadesVale $calendarioParcialidades,
    ) {}

    public function generar(CarbonImmutable $corte): int
    {
        $this->calendarioParcialidades->repararCobradosSinCalendario();
        $config = $this->configuracion->resolver(CarbonImmutable::now('UTC'));
        $corte = $corte->setTimezone($config['timezone']);
        $cutoff = $corte->utc();
        $this->asegurarCorteAnteriorConciliado($cutoff);
        if (DB::table('relation_process_runs')
            ->where('status', 'COMPLETED')
            ->where('cutoff_at', $cutoff)
            ->exists()) {
            return 0;
        }
        $paymentDeadline = $corte
            ->addDays($config['payment_deadline_days'])
            ->setTimeFromTimeString($config['payment_deadline_time']);
        $runId = (string) Str::uuid();
        $attempt = ((int) DB::table('relation_process_runs')->where('cutoff_at', $cutoff)->max('attempt')) + 1;
        DB::table('relation_process_runs')->insert(['id' => $runId, 'cutoff_at' => $cutoff, 'status' => 'RUNNING', 'attempt' => $attempt, 'configuration_snapshot' => json_encode($config), 'created_at' => now(), 'updated_at' => now()]);

        try {
            $count = DB::transaction(fn (): int => $this->procesar($runId, $corte, $cutoff, $paymentDeadline, $config));
            DB::table('relation_process_runs')->where('id', $runId)->update(['status' => 'COMPLETED', 'updated_at' => now()]);

            return $count;
        } catch (Throwable $error) {
            DB::table('relation_process_runs')->where('id', $runId)->update(['status' => 'FAILED', 'error' => Str::limit($error->getMessage(), 2000), 'updated_at' => now()]);
            throw $error;
        }
    }

    private function asegurarCorteAnteriorConciliado(CarbonImmutable $cutoff): void
    {
        $previousRunId = DB::table('relation_process_runs')
            ->where('status', 'COMPLETED')
            ->where('cutoff_at', '<', $cutoff)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('distributor_relations')
                    ->whereColumn('distributor_relations.process_run_id', 'relation_process_runs.id');
            })
            ->latest('cutoff_at')
            ->value('id');

        if ($previousRunId === null) {
            return;
        }

        $reconciled = AuditLog::query()
            ->where('entity_type', 'relation_process_run')
            ->where('entity_id', $previousRunId)
            ->where('event_name', 'ForcePaymentDeadlineCompleted')
            ->where('result', 'SUCCESS')
            ->exists();

        if (! $reconciled) {
            throw new \RuntimeException('PREVIOUS_CUTOFF_NOT_RECONCILED');
        }
    }

    private function procesar(string $runId, CarbonImmutable $corte, CarbonImmutable $cutoff, CarbonImmutable $paymentDeadline, array $config): int
    {
        $installments = ParcialidadVale::query()
            ->with(['vale.distribuidora.usuario', 'vale.distribuidora.sucursal', 'vale.distribuidora.lineaCredito', 'vale.distribuidora.coordinadorVigente.coordinator', 'vale.distribuidora.solicitud.domicilioActual', 'vale.cliente', 'vale.versionProducto', 'vale.versionCategoria'])
            ->whereNotNull('due_at')
            ->whereHas('vale', fn ($q) => $q->where('status', 'CASHED')->whereNotNull('cashed_at')->where('cashed_at', '<=', $cutoff))
            ->whereDoesntHave('relationItem')
            ->orderBy('voucher_id')
            ->orderBy('number')
            ->lockForUpdate()
            ->get()
            ->unique('voucher_id')
            ->values();
        $groups = $installments->groupBy(fn ($item) => $item->vale->distributor_id);
        foreach ($groups as $items) {
            $first = $items->first();
            $d = $first->vale->distribuidora;
            $line = $d->lineaCredito;
            $previousRelations = RelacionDistribuidora::query()
                ->where('distributor_id', $d->id)
                ->where('cutoff_at', '<', $cutoff)
                ->orderBy('cutoff_at')
                ->lockForUpdate()
                ->get();
            $previous = $previousRelations->last();
            $outstandingRelations = $previousRelations->filter(
                fn (RelacionDistribuidora $candidate): bool => bccomp($candidate->balance, '0', 4) > 0,
            );
            $carry = $outstandingRelations->reduce(function (array $total, RelacionDistribuidora $candidate): array {
                foreach ($this->saldoPendientePorComponente($candidate) as $component => $amount) {
                    $total[$component] = bcadd($total[$component], $amount, 4);
                }

                return $total;
            }, ['surcharge' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => '0.0000']);
            $carriedBalance = array_reduce($carry, fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
            $newPortfolio = $items->reduce(function (string $sum, $item): string {
                $clientPayment = (string) ($item->vale?->client_payment_per_fortnight ?? $item->client_payment);
                return bcadd($sum, $clientPayment, 4);
            }, '0.0000');
            $newMisvales = $items->reduce(function (string $sum, $item): string {
                $misvalesPayment = (string) ($item->vale?->misvales_payment_per_fortnight ?? $item->misvales_payment);
                return bcadd($sum, $misvalesPayment, 4);
            }, '0.0000');
            $portfolio = bcadd($newPortfolio, $carriedBalance, 4);
            $misvales = bcadd($newMisvales, $carriedBalance, 4);
            $relation = RelacionDistribuidora::create(['process_run_id' => $runId, 'distributor_id' => $d->id, 'branch_id' => $d->branch_id, 'previous_relation_id' => $previous?->id, 'cutoff_at' => $cutoff, 'advance_period_start' => $corte, 'advance_period_end' => $paymentDeadline->subDay()->endOfDay(), 'payment_deadline_at' => $paymentDeadline, 'payment_reference' => 'REL-'.$corte->format('YmdHi').'-'.$d->distributor_number, 'portfolio_total' => $portfolio, 'misvales_total' => $misvales, 'surcharge_total' => $carry['surcharge'], 'carried_balance' => $carriedBalance, 'carried_surcharge' => $carry['surcharge'], 'carried_interest' => $carry['interest'], 'carried_insurance' => $carry['insurance'], 'carried_commission' => $carry['commission'], 'carried_capital' => $carry['capital'], 'balance' => $misvales, 'header_snapshot' => ['number' => $d->distributor_number, 'name' => $d->usuario?->name, 'address' => $this->domicilio($d->solicitud?->domicilioActual), 'branch' => $d->sucursal?->name, 'coordinator' => $d->coordinadorVigente?->coordinator?->name, 'credit_line_total' => $line?->total_authorized, 'credit_available' => $line?->saldoDisponible(), 'configuration_versions' => $config['configuration_versions']], 'bank_snapshot' => $config['bank']]);
            foreach ($items as $item) {
                $client = $item->vale->cliente;
                $clientPayment = (string) ($item->vale?->client_payment_per_fortnight ?? $item->client_payment);
                $misvalesPayment = (string) ($item->vale?->misvales_payment_per_fortnight ?? $item->misvales_payment);
                $distributorProfit = (string) ($item->vale?->distributor_profit_per_fortnight ?? $item->distributor_profit);
                $misvalesCommission = bcsub(
                    $misvalesPayment,
                    $this->sumarImportes([$item->capital, $item->interest, $item->insurance]),
                    4,
                );
                if (bccomp($misvalesCommission, '0.0000', 4) < 0) {
                    $misvalesCommission = '0.0000';
                }
                DB::table('distributor_relation_items')->insert(['id' => (string) Str::uuid(), 'relation_id' => $relation->id, 'voucher_installment_id' => $item->id, 'snapshot' => json_encode(['product' => $item->vale->versionProducto?->name, 'client' => trim($client->first_name.' '.$client->first_last_name.' '.$client->second_last_name), 'folio' => $item->vale->folio, 'installment' => $item->number, 'total_installments' => $item->vale->fortnights_count, 'capital' => $item->capital, 'loan_commission' => $item->loan_commission, 'misvales_commission' => $misvalesCommission, 'interest' => $item->interest, 'insurance' => $item->insurance, 'distributor_profit' => $distributorProfit, 'distributor_profit_percentage' => $item->vale->distributor_profit_percentage, 'category_version_id' => $item->vale->category_version_id, 'category_version' => $item->vale->versionCategoria?->version, 'category_name' => $item->vale->versionCategoria?->name, 'base_payment' => $clientPayment, 'surcharge' => '0.0000', 'client_payment' => $clientPayment, 'misvales_payment' => $misvalesPayment, 'reconciled_payments' => '0.0000', 'balance' => $misvalesPayment, 'financial_status' => 'PENDING', 'classification' => null]), 'portfolio_amount' => $clientPayment, 'misvales_amount' => $misvalesPayment, 'created_at' => now()]);
            }
            foreach ($outstandingRelations as $outstandingRelation) {
                $outstandingRelation->update(['financial_status' => 'ROLLED_FORWARD', 'balance' => '0.0000', 'rolled_forward_to_id' => $relation->id, 'rolled_forward_at' => now(), 'rolled_forward_amount' => $outstandingRelation->balance]);
            }
            AuditLog::create(['entity_type' => 'distributor_relation', 'event_name' => 'RelationGenerated', 'entity_id' => $relation->id, 'new_value' => ['cutoff_at' => $cutoff->toIso8601String(), 'items' => $items->count(), 'new_balance' => $newMisvales, 'carried_balance' => $carriedBalance, 'previous_relation_id' => $previous?->id, 'balance' => $misvales], 'result' => 'SUCCESS']);
            $this->surpluses->programarDisponibles($relation, $d->usuario);

            // Notificar a la distribuidora sobre el nuevo saldo de corte
            $d->usuario->notify(new NotificacionEventoDominio([
                'title' => 'Nuevo corte generado',
                'description' => 'Tu corte ha sido generado. Tienes un saldo por pagar de $'.number_format((float) $relation->balance, 2).'. Fecha límite: '.$relation->payment_deadline_at->setTimezone($config['timezone'])->format('d/m/Y h:i A').'.',
                'deep_link' => '/cartera',
            ]));
        }

        return $groups->count();
    }

    private function saldoPendientePorComponente(?RelacionDistribuidora $relation): array
    {
        $empty = ['surcharge' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => '0.0000'];
        if ($relation === null || bccomp($relation->balance, '0', 4) <= 0) {
            return $empty;
        }

        $itemTotals = $relation->partidas()->get()->reduce(function (array $totals, $item) use ($relation): array {
            $snapshot = is_array($item->snapshot) ? $item->snapshot : json_decode($item->snapshot, true);
            $isOverdue = in_array($relation->financial_status, ['OVERDUE', 'ROLLED_FORWARD']) || ! empty($snapshot['distributor_profit_forfeited']);
            foreach (['interest', 'insurance', 'loan_commission', 'capital'] as $field) {
                $snapshotField = $field === 'loan_commission' && ! $isOverdue && array_key_exists('misvales_commission', $snapshot)
                    ? 'misvales_commission'
                    : $field;
                $totals[$field] = bcadd($totals[$field], (string) ($snapshot[$snapshotField] ?? '0.0000'), 4);
            }

            return $totals;
        }, ['interest' => '0.0000', 'insurance' => '0.0000', 'loan_commission' => '0.0000', 'capital' => '0.0000']);
        $paid = $relation->pagos()->reorder()->selectRaw('SUM(surcharge_applied) surcharge, SUM(interest_applied) interest, SUM(insurance_applied) insurance, SUM(commission_applied) commission, SUM(capital_applied) capital')->first();
        $totals = [
            'surcharge' => (string) $relation->surcharge_total,
            'interest' => bcadd((string) $relation->carried_interest, $itemTotals['interest'], 4),
            'insurance' => bcadd((string) $relation->carried_insurance, $itemTotals['insurance'], 4),
            'commission' => bcadd((string) $relation->carried_commission, $itemTotals['loan_commission'], 4),
            'capital' => bcadd((string) $relation->carried_capital, $itemTotals['capital'], 4),
        ];

        foreach ($totals as $component => $total) {
            $pending = bcsub($total, (string) ($paid->{$component} ?? '0.0000'), 4);
            $empty[$component] = bccomp($pending, '0', 4) > 0 ? $pending : '0.0000';
        }

        return $empty;
    }

    private function domicilio(?object $address): ?string
    {
        if ($address === null) {
            return null;
        }

        return collect([
            trim($address->street.' '.$address->exterior_number.' '.($address->interior_number ?? '')),
            $address->neighborhood,
            $address->postal_code,
            $address->city,
            $address->state,
        ])->filter(fn ($value) => filled($value))->implode(', ');
    }

    private function sumarImportes(array $amounts): string
    {
        return array_reduce(
            $amounts,
            static fn (string $total, string $amount): string => bcadd($total, $amount, 4),
            '0.0000',
        );
    }
}
