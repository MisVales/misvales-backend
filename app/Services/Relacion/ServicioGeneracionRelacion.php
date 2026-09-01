<?php

namespace App\Services\Relacion;

use App\Models\AuditLog;
use App\Models\Distribuidora;
use App\Models\ParcialidadVale;
use App\Models\RelacionDistribuidora;
use App\Models\RelacionPartidaDistribuidora;
use App\Notifications\NotificacionEventoDominio;
use App\Services\Excedente\ServicioExcedente;
use App\Services\Recargo\ServicioEvaluacionRecargo;
use App\Services\Vale\ServicioCalendarioParcialidadesVale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ServicioGeneracionRelacion
{
    public function __construct(
        private readonly ServicioExcedente $surpluses,
        private readonly ServicioConfiguracionRelacion $configuracion,
        private readonly ServicioCalendarioParcialidadesVale $calendarioParcialidades,
        private readonly ServicioSaldoValeRelacion $saldoVale,
        private readonly ServicioEvaluacionRecargo $recargos,
    ) {}

    public function generar(CarbonImmutable $corte): int
    {
        $this->calendarioParcialidades->repararCobradosSinCalendario();
        $config = $this->configuracion->resolver(CarbonImmutable::now('UTC'));
        $corte = $corte->setTimezone($config['timezone']);
        $cutoff = $corte->utc();
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

    public function repararOcurrenciasTerminales(RelacionDistribuidora $relation): int
    {
        return DB::transaction(function () use ($relation): int {
            $relation = RelacionDistribuidora::query()->lockForUpdate()->findOrFail($relation->id);
            if (in_array($relation->financial_status, ['SETTLED', 'ROLLED_FORWARD'], true) || $relation->rolled_forward_to_id !== null) {
                throw new RuntimeException('La relación ya fue liquidada o trasladada; repare la relación vigente de la cadena.');
            }

            $previous = $relation->anterior()->lockForUpdate()->first();
            $cutoff = CarbonImmutable::parse((string) $relation->getRawOriginal('cutoff_at'), 'UTC');
            $positions = $this->posicionesTerminales($previous, $cutoff);
            $result = $this->materializarOcurrenciasTerminales($relation, $positions, $cutoff);
            if ($result['count'] === 0) {
                return 0;
            }

            $relation->forceFill([
                'portfolio_total' => bcadd((string) $relation->portfolio_total, $result['charge'], 4),
                'misvales_total' => bcadd((string) $relation->misvales_total, $result['charge'], 4),
                'balance' => bcadd((string) $relation->balance, $result['charge'], 4),
            ])->save();
            AuditLog::create([
                'entity_type' => 'distributor_relation',
                'event_name' => 'TerminalOccurrencesRepaired',
                'entity_id' => $relation->id,
                'new_value' => [
                    'terminal_occurrences' => $result['count'],
                    'terminal_charge' => $result['charge'],
                    'balance' => $relation->balance,
                ],
                'result' => 'SUCCESS',
            ]);

            return $result['count'];
        });
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
        $distributorIds = $installments->pluck('vale.distributor_id')->unique()->filter();
        $carriedDistributorIds = RelacionDistribuidora::query()
            ->where('cutoff_at', '<', $cutoff)
            ->where('balance', '>', 0)
            ->pluck('distributor_id')
            ->unique();
        $allDistributorIds = $distributorIds->merge($carriedDistributorIds)->unique()->values();
        $processedCount = 0;
        foreach ($allDistributorIds as $distId) {
            $items = $installments->filter(fn ($item) => $item->vale->distributor_id === $distId);
            $previousRelations = RelacionDistribuidora::query()
                ->where('distributor_id', $distId)
                ->where('cutoff_at', '<', $cutoff)
                ->orderBy('cutoff_at')
                ->lockForUpdate()
                ->get();
            $previous = $previousRelations->last();
            $outstandingRelations = $previousRelations->filter(
                fn (RelacionDistribuidora $candidate): bool => bccomp($candidate->balance, '0', 4) > 0,
            );
            $outstandingRelations->each(fn (RelacionDistribuidora $candidate) => $this->recargos->aplicarAntesDeRollover($candidate, $cutoff));
            $outstandingRelations = $outstandingRelations->map(fn (RelacionDistribuidora $candidate) => $candidate->fresh());
            $previous = $previous?->fresh();
            $terminalPositions = $this->posicionesTerminales($previous, $cutoff);

            if ($items->isEmpty() && $outstandingRelations->isEmpty()) {
                continue;
            }

            $first = $items->first();
            $d = $first?->vale?->distribuidora ?? Distribuidora::with(['usuario', 'sucursal', 'lineaCredito', 'coordinadorVigente.coordinator', 'solicitud.domicilioActual'])->find($distId);
            if (! $d) {
                continue;
            }
            $line = $d->lineaCredito;
            $carry = $outstandingRelations->reduce(function (array $total, RelacionDistribuidora $candidate): array {
                foreach ($this->saldoPendientePorComponente($candidate) as $component => $amount) {
                    $total[$component] = bcadd($total[$component], $amount, 4);
                }

                return $total;
            }, ['surcharge' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => '0.0000']);
            $carriedBalance = array_reduce($carry, fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
            $newPortfolio = $items->reduce(function (string $sum, $item): string {
                // La última parcialidad puede absorber el residuo de la
                // división. Siempre se toma el importe materializado en ella,
                // no la cuota resumida del vale.
                $clientPayment = (string) ($item->client_payment ?? $item->vale?->client_payment_per_fortnight ?? '0.0000');

                return bcadd($sum, $clientPayment, 4);
            }, '0.0000');
            $terminalCharges = $terminalPositions->reduce(fn (string $sum, array $position): string => bcadd($sum, $this->terminalCharge($position['final_snapshot']), 4), '0.0000');
            $newPortfolio = bcadd($newPortfolio, $terminalCharges, 4);
            $newMisvales = $items->reduce(function (string $sum, $item): string {
                $misvalesPayment = (string) ($item->misvales_payment ?? $item->vale?->misvales_payment_per_fortnight ?? '0.0000');

                return bcadd($sum, $misvalesPayment, 4);
            }, '0.0000');
            $newMisvales = bcadd($newMisvales, $terminalCharges, 4);
            $portfolio = bcadd($newPortfolio, $carriedBalance, 4);
            $misvales = bcadd($newMisvales, $carriedBalance, 4);
            $relation = RelacionDistribuidora::create(['process_run_id' => $runId, 'distributor_id' => $d->id, 'branch_id' => $d->branch_id, 'previous_relation_id' => $previous?->id, 'cutoff_at' => $cutoff, 'advance_period_start' => $corte, 'advance_period_end' => $paymentDeadline->subDay()->endOfDay(), 'payment_deadline_at' => $paymentDeadline, 'payment_reference' => 'REL-'.$corte->format('YmdHi').'-'.$d->distributor_number, 'portfolio_total' => $portfolio, 'misvales_total' => $misvales, 'surcharge_total' => $carry['surcharge'], 'carried_balance' => $carriedBalance, 'carried_surcharge' => $carry['surcharge'], 'carried_interest' => $carry['interest'], 'carried_insurance' => $carry['insurance'], 'carried_commission' => $carry['commission'], 'carried_capital' => $carry['capital'], 'balance' => $misvales, 'header_snapshot' => ['number' => $d->distributor_number, 'name' => $d->usuario?->name, 'address' => $this->domicilio($d->solicitud?->domicilioActual), 'branch' => $d->sucursal?->name, 'coordinator' => $d->coordinadorVigente?->coordinator?->name, 'credit_line_total' => $line?->total_authorized, 'credit_available' => $line?->saldoDisponible(), 'configuration_versions' => $config['configuration_versions']], 'bank_snapshot' => $config['bank']]);
            $relation->header_snapshot = array_merge($relation->header_snapshot, ['late_fee' => $config['late_fee']]);
            $relation->save();
            $processedCount++;
            foreach ($items as $item) {
                $client = $item->vale->cliente;
                $clientPayment = (string) ($item->client_payment ?? $item->vale?->client_payment_per_fortnight ?? '0.0000');
                $misvalesPayment = (string) ($item->misvales_payment ?? $item->vale?->misvales_payment_per_fortnight ?? '0.0000');
                $distributorProfit = (string) ($item->distributor_profit ?? $item->vale?->distributor_profit_per_fortnight ?? '0.0000');
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
            DB::table('distributor_relation_items')->where('relation_id', $relation->id)->where('occurrence_type', 'INSTALLMENT')->whereNull('source_voucher_installment_id')->update([
                'source_voucher_installment_id' => DB::raw('voucher_installment_id'),
            ]);
            $this->materializarOcurrenciasTerminales($relation, $terminalPositions, $cutoff);
            foreach ($outstandingRelations as $outstandingRelation) {
                $transferred = $this->sumarImportes(array_values($this->saldoPendientePorComponente($outstandingRelation)));
                $outstandingRelation->update(['financial_status' => 'ROLLED_FORWARD', 'balance' => '0.0000', 'rolled_forward_to_id' => $relation->id, 'rolled_forward_at' => now(), 'rolled_forward_amount' => $transferred]);
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

        return $processedCount;
    }

    /** @return array{surcharge:string,interest:string,insurance:string,commission:string,capital:string} */
    public function saldoPendientePorComponente(?RelacionDistribuidora $relation): array
    {
        $empty = ['surcharge' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => '0.0000'];
        if ($relation === null || bccomp($relation->balance, '0', 4) <= 0) {
            return $empty;
        }

        $itemTotals = $relation->partidas()->get()->reduce(function (array $totals, $item) use ($relation): array {
            $snapshot = is_array($item->snapshot) ? $item->snapshot : json_decode($item->snapshot, true);
            $isFinalInstallment = (int) ($snapshot['installment'] ?? 0) === (int) ($snapshot['total_installments'] ?? -1);
            $isOverdue = (in_array($relation->financial_status, ['OVERDUE', 'ROLLED_FORWARD']) || ! empty($snapshot['distributor_profit_forfeited'])) && ! $isFinalInstallment;
            $canonical = $isOverdue
                ? (string) ($snapshot['base_payment'] ?? $item->portfolio_amount)
                : (string) ($snapshot['misvales_payment'] ?? $item->misvales_amount);
            $commission = bcsub($canonical, $this->sumarImportes([
                (string) ($snapshot['capital'] ?? '0.0000'),
                (string) ($snapshot['interest'] ?? '0.0000'),
                (string) ($snapshot['insurance'] ?? '0.0000'),
            ]), 4);
            foreach (['interest', 'insurance', 'loan_commission', 'capital'] as $field) {
                $snapshotField = $field === 'loan_commission' && ! $isOverdue && array_key_exists('misvales_commission', $snapshot)
                    ? 'misvales_commission'
                    : $field;
                $amount = $field === 'loan_commission' ? $commission : (string) ($snapshot[$snapshotField] ?? '0.0000');
                $totals[$field] = bcadd($totals[$field], $amount, 4);
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

    /** @return Collection<int, array<string, mixed>> */
    private function posicionesTerminales(?RelacionDistribuidora $previous, CarbonImmutable $cutoff): Collection
    {
        if ($previous === null || $previous->payment_deadline_at === null || ! $previous->payment_deadline_at->lessThan($cutoff)) {
            return collect();
        }

        return collect($this->saldoVale->posiciones($previous))
            ->filter(fn (array $position): bool => $position['is_pending'] && is_array($position['final_snapshot']))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $positions
     * @return array{count:int,charge:string}
     */
    private function materializarOcurrenciasTerminales(RelacionDistribuidora $relation, Collection $positions, CarbonImmutable $cutoff): array
    {
        $count = 0;
        $totalCharge = '0.0000';

        foreach ($positions as $position) {
            $source = ParcialidadVale::query()->with(['vale.cliente', 'vale.versionProducto'])->findOrFail($position['source_voucher_installment_id']);
            $previousTerminal = $position['last_terminal_occurrence'];
            $charge = $this->terminalCharge($position['final_snapshot']);
            $sequence = $position['next_terminal_sequence'];
            $existing = RelacionPartidaDistribuidora::query()
                ->where('source_voucher_installment_id', $source->id)
                ->where('terminal_sequence', $sequence)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($existing->relation_id !== $relation->id) {
                    throw new RuntimeException("La ocurrencia terminal {$sequence} ya pertenece a otra relación.");
                }

                continue;
            }

            $opening = $position['balance'];
            $snapshot = array_merge($position['final_snapshot'], [
                'is_terminal_overdue_cycle' => true, 'terminal_sequence' => $sequence,
                'terminal_opening_balance' => $opening, 'terminal_charge' => $charge,
                'terminal_resulting_balance' => bcadd($opening, $charge, 4),
                'terminal_source_relation_id' => $relation->previous_relation_id, 'terminal_cycle_at' => $cutoff->toIso8601String(),
                'source_voucher_installment_id' => $source->id, 'surcharge' => '0.0000',
                'capital' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000',
                'loan_commission' => $charge, 'misvales_commission' => $charge,
                'client_payment' => $charge, 'misvales_payment' => $charge,
                'reconciled_payments' => '0.0000', 'balance' => $charge, 'financial_status' => 'PENDING',
            ]);
            RelacionPartidaDistribuidora::query()->create([
                'id' => (string) Str::uuid(), 'relation_id' => $relation->id, 'voucher_installment_id' => null,
                'occurrence_type' => 'TERMINAL_OVERDUE', 'source_voucher_installment_id' => $source->id,
                'previous_terminal_occurrence_id' => $previousTerminal?->id, 'terminal_sequence' => $sequence,
                'snapshot' => $snapshot, 'portfolio_amount' => $charge, 'misvales_amount' => $charge, 'created_at' => now(),
            ]);
            $count++;
            $totalCharge = bcadd($totalCharge, $charge, 4);
        }

        return ['count' => $count, 'charge' => $totalCharge];
    }

    private function terminalCharge(array $snapshot): string
    {
        $charge = bcsub((string) ($snapshot['base_payment'] ?? '0.0000'), (string) ($snapshot['misvales_payment'] ?? '0.0000'), 4);

        return bccomp($charge, '0', 4) > 0 ? $charge : '0.0000';
    }
}
