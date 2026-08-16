<?php

namespace App\Services\Relacion;

use App\Models\AuditLog;
use App\Models\ParcialidadVale;
use App\Models\RelacionDistribuidora;
use App\Services\Excedente\ServicioExcedente;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ServicioGeneracionRelacion
{
    public function __construct(
        private readonly ServicioExcedente $surpluses,
        private readonly ServicioConfiguracionRelacion $configuracion,
    ) {}

    public function generar(CarbonImmutable $corte): int
    {
        $config = $this->configuracion->resolver(CarbonImmutable::now('UTC'));
        $corte = $corte->setTimezone($config['timezone']);
        $cutoff = $corte->utc();
        $runId = (string) Str::uuid();
        $attempt = ((int) DB::table('relation_process_runs')->where('cutoff_at', $cutoff)->max('attempt')) + 1;
        DB::table('relation_process_runs')->insert(['id' => $runId, 'cutoff_at' => $cutoff, 'status' => 'RUNNING', 'attempt' => $attempt, 'configuration_snapshot' => json_encode($config), 'created_at' => now(), 'updated_at' => now()]);

        try {
            $count = DB::transaction(fn (): int => $this->procesar($runId, $corte, $cutoff, $config));
            DB::table('relation_process_runs')->where('id', $runId)->update(['status' => 'COMPLETED', 'updated_at' => now()]);

            return $count;
        } catch (Throwable $error) {
            DB::table('relation_process_runs')->where('id', $runId)->update(['status' => 'FAILED', 'error' => Str::limit($error->getMessage(), 2000), 'updated_at' => now()]);
            throw $error;
        }
    }

    private function procesar(string $runId, CarbonImmutable $corte, CarbonImmutable $cutoff, array $config): int
    {
        $groups = ParcialidadVale::query()->with(['vale.distribuidora.usuario', 'vale.distribuidora.sucursal', 'vale.distribuidora.lineaCredito', 'vale.distribuidora.coordinadorVigente.coordinator', 'vale.distribuidora.solicitud.domicilioActual', 'vale.distribuidora.cuentaPuntos', 'vale.cliente', 'vale.versionProducto'])->whereNotNull('due_at')->where('due_at', '<=', $cutoff)->whereHas('vale', fn ($q) => $q->where('status', 'CASHED'))->whereDoesntHave('relationItem')->lockForUpdate()->get()->groupBy(fn ($item) => $item->vale->distributor_id);
        foreach ($groups as $items) {
            $first = $items->first();
            $d = $first->vale->distribuidora;
            $line = $d->lineaCredito;
            $portfolio = $items->reduce(fn (string $sum, $item) => bcadd($sum, $item->client_payment, 4), '0.0000');
            $misvales = $items->reduce(fn (string $sum, $item) => bcadd($sum, $item->misvales_payment, 4), '0.0000');
            $relation = RelacionDistribuidora::create(['process_run_id' => $runId, 'distributor_id' => $d->id, 'branch_id' => $d->branch_id, 'cutoff_at' => $cutoff, 'advance_period_start' => $corte->addDays($config['early_payment_period']['start'])->utc(), 'advance_period_end' => $corte->addDays($config['early_payment_period']['end'])->endOfDay()->utc(), 'payment_deadline_at' => $corte->addDays($config['payment_deadline_days'])->setTimeFromTimeString($config['payment_deadline_time'])->utc(), 'payment_reference' => 'REL-'.$corte->format('YmdHi').'-'.$d->distributor_number, 'portfolio_total' => $portfolio, 'misvales_total' => $misvales, 'balance' => $misvales, 'header_snapshot' => ['number' => $d->distributor_number, 'name' => $d->usuario?->name, 'address' => $this->domicilio($d->solicitud?->domicilioActual), 'branch' => $d->sucursal?->name, 'coordinator' => $d->coordinadorVigente?->coordinator?->name, 'credit_line_total' => $line?->total_authorized, 'credit_available' => $line?->saldoDisponible(), 'points' => $d->cuentaPuntos?->balance ?? 0, 'configuration_versions' => $config['configuration_versions']], 'bank_snapshot' => $config['bank']]);
            foreach ($items as $item) {
                $client = $item->vale->cliente;
                DB::table('distributor_relation_items')->insert(['id' => (string) Str::uuid(), 'relation_id' => $relation->id, 'voucher_installment_id' => $item->id, 'snapshot' => json_encode(['product' => $item->vale->versionProducto?->name, 'client' => trim($client->first_name.' '.$client->first_last_name.' '.$client->second_last_name), 'folio' => $item->vale->folio, 'installment' => $item->number, 'total_installments' => $item->vale->fortnights_count, 'capital' => $item->capital, 'loan_commission' => $item->loan_commission, 'interest' => $item->interest, 'insurance' => $item->insurance, 'distributor_profit' => $item->distributor_profit, 'base_payment' => $item->misvales_payment, 'surcharge' => '0.0000', 'client_payment' => $item->client_payment, 'misvales_payment' => $item->misvales_payment, 'reconciled_payments' => '0.0000', 'balance' => $item->misvales_payment, 'financial_status' => 'PENDING', 'classification' => null]), 'portfolio_amount' => $item->client_payment, 'misvales_amount' => $item->misvales_payment, 'created_at' => now()]);
            }
            AuditLog::create(['entity_type' => 'distributor_relation', 'event_name' => 'RelationGenerated', 'entity_id' => $relation->id, 'new_value' => ['cutoff_at' => $cutoff->toIso8601String(), 'items' => $items->count(), 'balance' => $misvales], 'result' => 'SUCCESS']);
            $this->surpluses->aplicarDisponibles($relation);
        }

        return $groups->count();
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
}
