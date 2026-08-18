<?php

namespace App\Services\Credito;

use App\Helpers\AuditHelper;
use App\Models\LineaCredito;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CalculadorSaldoCredito
{
    /**
     * Calcula el saldo disponible a partir del total autorizado y el saldo usado.
     * Utiliza escala de 4 decimales según las reglas de negocio.
     */
    public function calcular(string $totalAuthorized, string $usedBalance): array
    {
        // Validar que used_balance >= 0
        if (bccomp($usedBalance, '0.0000', 4) < 0) {
            throw new InvalidArgumentException('Estado inconsistente: used_balance no puede ser negativo.');
        }

        // Validar que used_balance <= total_authorized
        if (bccomp($usedBalance, $totalAuthorized, 4) > 0) {
            throw new InvalidArgumentException('Estado inconsistente: used_balance no puede ser mayor a total_authorized.');
        }

        // available_balance = total_authorized - used_balance
        $availableBalance = bcsub($totalAuthorized, $usedBalance, 4);

        return [
            'total_authorized' => bcadd($totalAuthorized, '0', 4),
            'used_balance' => bcadd($usedBalance, '0', 4),
            'available_balance' => $availableBalance,
        ];
    }

    /**
     * Reconstruye el libro desde los movimientos y emite alertas técnicas/auditoría
     * si detecta una inconsistencia en los saldos reales de la línea.
     */
    public function verificarConsistencia(LineaCredito $linea): void
    {
        // Obtener el último movimiento inmutable de la línea
        $ultimoMovimiento = $linea->movimientos()->orderBy('sequence', 'desc')->first();

        if (! $ultimoMovimiento) {
            return; // No hay movimientos aún, nada que verificar contra el ledger
        }

        $ledgerTotalAuthorized = $ultimoMovimiento->total_authorized_after;
        $ledgerUsedBalance = $ultimoMovimiento->used_balance_after;

        $inconsistenciaTotal = bccomp($linea->total_authorized, $ledgerTotalAuthorized, 4) !== 0;
        $inconsistenciaUsado = bccomp($linea->used_balance, $ledgerUsedBalance, 4) !== 0;

        if ($inconsistenciaTotal || $inconsistenciaUsado) {
            $reason = "Inconsistencia grave detectada en línea {$linea->id}. ".
                "Físico (auth: {$linea->total_authorized}, usado: {$linea->used_balance}). ".
                "Ledger (auth: {$ledgerTotalAuthorized}, usado: {$ledgerUsedBalance}).";

            Log::critical($reason);

            AuditHelper::log(
                'CREDIT_LEDGER_INCONSISTENCY',
                'credit_lines',
                $linea->id,
                auth()->id(),
                null,
                [
                    'physical_total_authorized' => $linea->total_authorized,
                    'physical_used_balance' => $linea->used_balance,
                ],
                [
                    'ledger_total_authorized' => $ledgerTotalAuthorized,
                    'ledger_used_balance' => $ledgerUsedBalance,
                ],
                $reason,
                'ERROR'
            );

            // Importante: No corregir silenciosamente.
            // Lanzamos excepción para abortar la operación que lo detectó.
            throw new InvalidArgumentException('Inconsistencia en el libro de la línea de crédito. Reconstrucción fallida.');
        }
    }
}
