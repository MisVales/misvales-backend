<?php

namespace App\Services\Credito;

use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use Illuminate\Support\Facades\DB;
use Exception;

class ServicioRegistroMovimientoCredito
{
    public function registrar(LineaCredito $linea, string $tipoMovimiento, string $monto, string $motivo, ?string $referenciaId = null, ?string $referenciaTipo = null): MovimientoLineaCredito
    {
        return DB::transaction(function () use ($linea, $tipoMovimiento, $monto, $motivo, $referenciaId, $referenciaTipo) {
            $saldoAnterior = (string) $linea->used_balance;
            $saldoNuevo = $saldoAnterior;
            $montoAutorizadoAnterior = (string) $linea->total_authorized;
            $montoAutorizadoNuevo = $montoAutorizadoAnterior;

            if ($tipoMovimiento === 'USAGE') {
                $saldoNuevo = bcadd($saldoAnterior, $monto, 2);
                $linea->used_balance = $saldoNuevo;
            } elseif ($tipoMovimiento === 'PAYMENT') {
                $saldoNuevo = bcsub($saldoAnterior, $monto, 2);
                $linea->used_balance = $saldoNuevo;
            } elseif ($tipoMovimiento === 'INCREASE') {
                $montoAutorizadoNuevo = bcadd($montoAutorizadoAnterior, $monto, 2);
                $linea->total_authorized = $montoAutorizadoNuevo;
            } elseif ($tipoMovimiento === 'DECREASE') {
                $montoAutorizadoNuevo = bcsub($montoAutorizadoAnterior, $monto, 2);
                $linea->total_authorized = $montoAutorizadoNuevo;
            } else {
                throw new Exception("Tipo de movimiento no válido: {$tipoMovimiento}");
            }

            // Invariantes obligatorios:
            // 0 <= used_balance <= total_authorized
            if (bccomp($linea->used_balance, '0.00', 2) < 0) {
                throw new Exception("Violación de Invariante: El saldo utilizado no puede ser negativo.");
            }
            if (bccomp($linea->used_balance, $linea->total_authorized, 2) > 0) {
                throw new Exception("Violación de Invariante: El saldo utilizado ({$linea->used_balance}) no puede ser mayor que el autorizado ({$linea->total_authorized}).");
            }

            // Al guardar, el trait HasOptimisticLocking validará el lock_version.
            $linea->save();

            $movimiento = MovimientoLineaCredito::create([
                'credit_line_id' => $linea->id,
                'type' => $tipoMovimiento,
                'amount' => $monto,
                'balance_before' => $tipoMovimiento === 'USAGE' || $tipoMovimiento === 'PAYMENT' ? $saldoAnterior : $montoAutorizadoAnterior,
                'balance_after' => $tipoMovimiento === 'USAGE' || $tipoMovimiento === 'PAYMENT' ? $saldoNuevo : $montoAutorizadoNuevo,
                'source_type' => $referenciaTipo ?? 'Manual',
                'source_id' => $referenciaId ?? \Illuminate\Support\Str::uuid()->toString(),
                'created_by' => auth()->id() ?? '00000000-0000-0000-0000-000000000000',
            ]);

            return $movimiento;
        });
    }
}
