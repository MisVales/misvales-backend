<?php

namespace App\Services\Vale;

use App\Contracts\Credito\VerificadorDisponibilidadCredito;
use App\Enums\EstadoVale;
use App\Enums\TipoMovimientoLineaCredito;
use App\Exceptions\ExcepcionVale;
use App\Helpers\AuditHelper;
use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use App\Models\OutboxEvent;
use App\Models\RestriccionUsoCredito;
use App\Models\TransaccionCajaVale;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Support\Facades\DB;

final class ServicioCajaVale
{
    public function __construct(private readonly VerificadorDisponibilidadCredito $credito) {}

    public function liberar(Vale $vale, User $cajera, int $version): Vale
    {
        return DB::transaction(function () use ($vale, $cajera, $version): Vale {
            $vale = Vale::query()->lockForUpdate()->findOrFail($vale->id);
            $this->validarCajera($cajera, $vale);
            if ($vale->lock_version !== $version) {
                throw new ExcepcionVale('VOUCHER_VERSION_CONFLICT', 'El vale fue modificado por otra operación.', 409);
            }
            if ($vale->status !== EstadoVale::GENERADO) {
                throw new ExcepcionVale('VOUCHER_STATUS_INVALID', 'Solo un vale generado puede liberarse.', 409);
            }
            $vale->forceFill(['status' => EstadoVale::LIBERADO, 'released_by' => $cajera->id, 'released_at' => now(), 'lock_version' => $vale->lock_version + 1])->save();
            AuditHelper::log('VOUCHER_RELEASED', 'vouchers', $vale->id, $cajera->id, $vale->branch_id, ['status' => EstadoVale::GENERADO->value], ['status' => EstadoVale::LIBERADO->value]);

            return $vale->refresh();
        });
    }

    public function feriar(Vale $vale, User $cajera, string $numeroTransaccion, int $version): Vale
    {
        return DB::transaction(function () use ($vale, $cajera, $numeroTransaccion, $version): Vale {
            $vale = Vale::query()->lockForUpdate()->findOrFail($vale->id);
            $this->validarCajera($cajera, $vale);
            if ($vale->lock_version !== $version) {
                throw new ExcepcionVale('VOUCHER_VERSION_CONFLICT', 'El vale fue modificado por otra operación.', 409);
            }
            if ($vale->status !== EstadoVale::LIBERADO) {
                throw new ExcepcionVale('VOUCHER_STATUS_INVALID', 'Solo un vale liberado puede feriarse.', 409);
            }
            if (TransaccionCajaVale::query()->where('bank_transaction_number', $numeroTransaccion)->exists()) {
                throw new ExcepcionVale('BANK_TRANSACTION_ALREADY_USED', 'El número de transacción ya fue utilizado.', 409);
            }

            $linea = LineaCredito::query()->lockForUpdate()->findOrFail($vale->credit_line_id);
            $resultado = $this->credito->evaluar($vale->distributor_id, (string) $vale->capital, $vale->id);
            if (! $resultado->capital_is_available) {
                throw new ExcepcionVale('CREDIT_INSUFFICIENT', 'La línea ya no cubre el capital del vale.', 409);
            }
            if (! $resultado->capital_satisfies_restriction) {
                throw new ExcepcionVale('CREDIT_50_PERCENT_RULE_NOT_SATISFIED', 'El vale ya no satisface la restricción vigente.', 409);
            }

            $usadoAntes = (string) $linea->used_balance;
            $usadoDespues = bcadd($usadoAntes, (string) $vale->capital, 4);
            if (bccomp($usadoDespues, (string) $linea->total_authorized, 4) > 0) {
                throw new ExcepcionVale('CREDIT_INSUFFICIENT', 'El feriado excedería la línea autorizada.', 409);
            }
            $linea->forceFill(['used_balance' => $usadoDespues, 'lock_version' => $linea->lock_version + 1])->save();
            $secuencia = ((int) MovimientoLineaCredito::query()->where('credit_line_id', $linea->id)->max('sequence')) + 1;
            MovimientoLineaCredito::query()->create(['credit_line_id' => $linea->id, 'distributor_id' => $linea->distributor_id, 'sequence' => $secuencia, 'type' => TipoMovimientoLineaCredito::VOUCHER_CASHED, 'amount' => $vale->capital, 'total_authorized_before' => $linea->total_authorized, 'total_authorized_after' => $linea->total_authorized, 'used_balance_before' => $usadoAntes, 'used_balance_after' => $usadoDespues, 'source_type' => 'VOUCHER', 'source_id' => $vale->id, 'reason' => 'Capital feriado en caja', 'performed_by' => $cajera->id, 'authorized_by' => $cajera->id, 'idempotency_key' => 'voucher-cashed:'.$vale->id, 'occurred_at' => now()]);

            if ($resultado->restriction_id) {
                RestriccionUsoCredito::query()->whereKey($resultado->restriction_id)->whereIn('status', ['ACTIVE', 'RESERVED'])->update(['status' => 'CONSUMED', 'reserved_voucher_id' => $vale->id, 'reserved_at' => DB::raw('COALESCE(reserved_at, NOW())'), 'consumed_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
            }
            TransaccionCajaVale::query()->create(['voucher_id' => $vale->id, 'bank_transaction_number' => $numeroTransaccion, 'cashier_id' => $cajera->id, 'branch_id' => $vale->branch_id, 'cashed_at' => now()]);
            $vale->forceFill(['status' => EstadoVale::FERIADO, 'cashed_by' => $cajera->id, 'cashed_at' => now(), 'lock_version' => $vale->lock_version + 1])->save();
            AuditHelper::log('VOUCHER_CASHED', 'vouchers', $vale->id, $cajera->id, $vale->branch_id, ['status' => EstadoVale::LIBERADO->value, 'used_balance' => $usadoAntes], ['status' => EstadoVale::FERIADO->value, 'used_balance' => $usadoDespues]);
            OutboxEvent::query()->create(['event_type' => 'VoucherCashed', 'payload' => ['voucher_id' => $vale->id, 'distributor_id' => $vale->distributor_id, 'branch_id' => $vale->branch_id], 'status' => 'PENDING']);

            return $vale->refresh();
        }, 3);
    }

    private function validarCajera(User $actor, Vale $vale): void
    {
        if (! $actor->hasPermissionTo('vouchers.cash_branch') || ! $actor->hasScopeForBranch($vale->branch_id)) {
            throw new ExcepcionVale('VOUCHER_BRANCH_FORBIDDEN', 'El vale no pertenece al alcance de caja.', 404);
        }
    }
}
