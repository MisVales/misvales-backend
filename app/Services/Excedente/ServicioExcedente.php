<?php

namespace App\Services\Excedente;

use App\Models\ExcedenteDistribuidora;
use App\Models\RelacionDistribuidora;
use App\Models\SolicitudDevolucionExcedente;
use App\Models\User;
use App\Services\Pago\ServicioAplicacionPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ServicioExcedente
{
    public function __construct(private ServicioAplicacionPago $payments) {}

    public function elegirCredito(ExcedenteDistribuidora $surplus, User $actor): ExcedenteDistribuidora
    {
        abort_unless($actor->distribuidora?->id === $surplus->distributor_id, 403);

        return DB::transaction(function () use ($surplus) {
            $s = ExcedenteDistribuidora::whereKey($surplus->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== 'PENDING_DECISION') {
                throw new RuntimeException('SURPLUS_ALREADY_DECIDED');
            }$s->update(['status' => 'CREDIT_BALANCE']);

            return $s->fresh();
        });
    }

    public function solicitarDevolucion(ExcedenteDistribuidora $surplus, User $actor): SolicitudDevolucionExcedente
    {
        abort_unless($actor->distribuidora?->id === $surplus->distributor_id, 403);

        return DB::transaction(function () use ($surplus, $actor) {
            $s = ExcedenteDistribuidora::whereKey($surplus->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== 'PENDING_DECISION') {
                throw new RuntimeException('SURPLUS_ALREADY_DECIDED');
            }$d = $actor->distribuidora;
            $s->update(['available_amount' => '0.0000', 'reserved_amount' => $s->original_amount, 'status' => 'REFUND_PENDING']);

            return SolicitudDevolucionExcedente::create(['surplus_id' => $s->id, 'branch_id' => $d->branch_id, 'amount' => $s->original_amount, 'requested_by' => $actor->id]);
        });
    }

    public function aplicarDisponibles(RelacionDistribuidora $relation): void
    {
        $credits = ExcedenteDistribuidora::where('distributor_id', $relation->distributor_id)->where('status', 'CREDIT_BALANCE')->where('available_amount', '>', 0)->oldest()->get();
        foreach ($credits as $credit) {
            DB::transaction(function () use ($credit, $relation) {
                $s = ExcedenteDistribuidora::whereKey($credit->id)->lockForUpdate()->firstOrFail();
                $r = RelacionDistribuidora::whereKey($relation->id)->lockForUpdate()->firstOrFail();
                if (bccomp($s->available_amount, '0', 4) <= 0 || bccomp($r->balance, '0', 4) <= 0) {
                    return;
                }$amount = bccomp($s->available_amount, $r->balance, 4) > 0 ? $r->balance : $s->available_amount;
                $applicationId = (string) Str::uuid();
                DB::table('surplus_applications')->insert(['id' => $applicationId, 'surplus_id' => $s->id, 'relation_id' => $r->id, 'amount' => $amount, 'applied_at' => now()]);
                $this->payments->aplicarSaldoFavor($amount, now()->toImmutable(), $r, $applicationId);
                $s->available_amount = bcsub($s->available_amount, $amount, 4);
                if (bccomp($s->available_amount, '0', 4) === 0) {
                    $s->status = 'CONSUMED';
                }$s->save();
            });
        }
    }
}
