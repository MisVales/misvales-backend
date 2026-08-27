<?php

namespace App\Services\Vale;

use App\Enums\EstadoRestriccionUsoCredito;
use App\Enums\EstadoVale;
use App\Enums\TipoMovimientoLineaCredito;
use App\Exceptions\ExcepcionVale;
use App\Helpers\AuditHelper;
use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use App\Models\OutboxEvent;
use App\Models\RestriccionUsoCredito;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Support\Facades\DB;

final class ServicioCancelacionVale
{
    public function cancelar(Vale $vale, User $actor): Vale
    {
        return DB::transaction(function () use ($vale, $actor): Vale {
            $locked = Vale::query()->whereKey($vale->id)->lockForUpdate()->firstOrFail();
            if (! $actor->hasPermissionTo('vouchers.create_own') || $actor->distribuidora?->id !== $locked->distributor_id) {
                throw new ExcepcionVale('VOUCHER_CANCELLATION_FORBIDDEN', 'No puedes cancelar un vale de otra distribuidora.', 403);
            }
            if (! in_array($locked->status, [EstadoVale::GENERADO, EstadoVale::VALIDACION_CAJA, EstadoVale::CORRECCION_PENDIENTE, EstadoVale::LIBERADO], true)) {
                throw new ExcepcionVale('VOUCHER_CANCELLATION_NOT_ALLOWED', 'Solo puedes cancelar el vale antes de que sea feriado.', 409);
            }

            $previousStatus = $locked->status->value;
            $movimientoEmision = MovimientoLineaCredito::query()
                ->where('source_type', 'VOUCHER_ISSUANCE')
                ->where('source_id', $locked->id)
                ->first();
            if ($movimientoEmision) {
                $linea = LineaCredito::query()->whereKey($locked->credit_line_id)->lockForUpdate()->firstOrFail();
                $usadoAntes = (string) $linea->used_balance;
                $usadoDespues = bcsub($usadoAntes, (string) $locked->capital, 4);
                if (bccomp($usadoDespues, '0.0000', 4) < 0) {
                    throw new ExcepcionVale('CREDIT_BALANCE_INCONSISTENT', 'No fue posible devolver el crédito del vale.', 409);
                }
                $linea->forceFill(['used_balance' => $usadoDespues, 'lock_version' => $linea->lock_version + 1])->save();
                $secuencia = ((int) MovimientoLineaCredito::query()->where('credit_line_id', $linea->id)->max('sequence')) + 1;
                MovimientoLineaCredito::query()->create([
                    'credit_line_id' => $linea->id,
                    'distributor_id' => $linea->distributor_id,
                    'sequence' => $secuencia,
                    'type' => TipoMovimientoLineaCredito::VOUCHER_CANCELLED,
                    'amount' => $locked->capital,
                    'total_authorized_before' => $linea->total_authorized,
                    'total_authorized_after' => $linea->total_authorized,
                    'used_balance_before' => $usadoAntes,
                    'used_balance_after' => $usadoDespues,
                    'source_type' => 'VOUCHER_CANCELLATION',
                    'source_id' => $locked->id,
                    'reason' => 'Crédito devuelto por cancelación del vale',
                    'performed_by' => $actor->id,
                    'authorized_by' => $actor->id,
                    'idempotency_key' => 'voucher-cancelled:'.$locked->id,
                    'occurred_at' => now(),
                ]);
            }
            $locked->update(['status' => EstadoVale::CANCELADO, 'lock_version' => $locked->lock_version + 1]);
            if ($locked->credit_restriction_id !== null) {
                RestriccionUsoCredito::query()
                    ->whereKey($locked->credit_restriction_id)
                    ->where('status', EstadoRestriccionUsoCredito::RESERVADA)
                    ->where('reserved_voucher_id', $locked->id)
                    ->lockForUpdate()
                    ->update([
                        'status' => EstadoRestriccionUsoCredito::ACTIVA,
                        'reserved_voucher_id' => null,
                        'reserved_at' => null,
                        'lock_version' => DB::raw('lock_version + 1'),
                    ]);
            }

            AuditHelper::log('VOUCHER_CANCELLED_BY_DISTRIBUTOR', 'vouchers', $locked->id, $actor->id, $locked->branch_id, ['status' => $previousStatus], ['status' => EstadoVale::CANCELADO->value]);
            OutboxEvent::query()->create(['event_type' => 'VoucherCancelled', 'payload' => ['voucher_id' => $locked->id, 'folio' => $locked->folio], 'status' => 'PENDING']);

            return $locked->fresh()->load(['cliente', 'distribuidora.usuario', 'versionProducto', 'versionCategoria', 'parcialidades']);
        }, 3);
    }
}
