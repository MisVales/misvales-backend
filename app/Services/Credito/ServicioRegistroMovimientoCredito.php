<?php

namespace App\Services\Credito;

use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioRegistroMovimientoCredito
{
    public function registrar(LineaCredito $linea, string $tipoMovimiento, string $monto, string $motivo, ?string $referenciaId = null, ?string $referenciaTipo = null): MovimientoLineaCredito
    {
        return DB::transaction(function () use ($linea, $tipoMovimiento, $monto, $motivo, $referenciaId, $referenciaTipo) {
            // Recargar línea de crédito con bloqueo pesimista
            $lineaBloqueada = LineaCredito::where('id', $linea->id)->lockForUpdate()->first();

            if (! $lineaBloqueada) {
                throw new Exception('Línea de crédito no encontrada durante el registro del movimiento.');
            }

            $saldoAnterior = (string) $lineaBloqueada->used_balance;
            $saldoNuevo = $saldoAnterior;
            $montoAutorizadoAnterior = (string) $lineaBloqueada->total_authorized;
            $montoAutorizadoNuevo = $montoAutorizadoAnterior;

            if ($tipoMovimiento === 'USAGE') {
                $saldoNuevo = bcadd($saldoAnterior, $monto, 2);
                $lineaBloqueada->used_balance = $saldoNuevo;
            } elseif ($tipoMovimiento === 'PAYMENT') {
                $saldoNuevo = bcsub($saldoAnterior, $monto, 2);
                $lineaBloqueada->used_balance = $saldoNuevo;
            } elseif ($tipoMovimiento === 'INCREASE') {
                $montoAutorizadoNuevo = bcadd($montoAutorizadoAnterior, $monto, 2);
                $lineaBloqueada->total_authorized = $montoAutorizadoNuevo;
            } elseif ($tipoMovimiento === 'DECREASE') {
                $montoAutorizadoNuevo = bcsub($montoAutorizadoAnterior, $monto, 2);
                $lineaBloqueada->total_authorized = $montoAutorizadoNuevo;
            } else {
                throw new Exception("Tipo de movimiento no válido: {$tipoMovimiento}");
            }

            // Invariantes obligatorios:
            // 0 <= used_balance <= total_authorized
            if (bccomp($lineaBloqueada->used_balance, '0.00', 2) < 0) {
                throw new Exception('Violación de Invariante: El saldo utilizado no puede ser negativo.');
            }
            if (bccomp($lineaBloqueada->used_balance, $lineaBloqueada->total_authorized, 2) > 0) {
                throw new Exception("Violación de Invariante: El saldo utilizado ({$lineaBloqueada->used_balance}) no puede ser mayor que el autorizado ({$lineaBloqueada->total_authorized}).");
            }

            // Incrementar lock_version para validación de bloqueo optimista
            $lineaBloqueada->lock_version = $lineaBloqueada->lock_version + 1;
            $lineaBloqueada->save();

            $sequence = MovimientoLineaCredito::where('credit_line_id', $lineaBloqueada->id)->max('sequence') + 1;

            $movimiento = MovimientoLineaCredito::create([
                'credit_line_id' => $lineaBloqueada->id,
                'distributor_id' => $lineaBloqueada->distributor_id,
                'sequence' => $sequence,
                'type' => $tipoMovimiento,
                'amount' => $monto,
                'total_authorized_before' => $montoAutorizadoAnterior,
                'total_authorized_after' => $montoAutorizadoNuevo,
                'used_balance_before' => $saldoAnterior,
                'used_balance_after' => $saldoNuevo,
                'source_type' => $referenciaTipo ?? 'Manual',
                'source_id' => $referenciaId ?? Str::uuid()->toString(),
                'reason' => $motivo,
                'performed_by' => auth()->id(),
                'authorized_by' => auth()->id(),
                'occurred_at' => now(),
            ]);

            return $movimiento;
        });
    }
}
