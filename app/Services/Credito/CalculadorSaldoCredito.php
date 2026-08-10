<?php

namespace App\Services\Credito;

use App\Models\LineaCredito;

class CalculadorSaldoCredito
{
    /**
     * Calcula el saldo disponible tomando en cuenta las restricciones activas.
     * Devuelve los valores como strings para mantener precisión decimal.
     */
    public function calcular(LineaCredito $linea): array
    {
        $montoAutorizado = (string) $linea->authorized_amount;
        $saldoUtilizado = (string) $linea->used_balance;
        
        $montoRestringido = (string) $linea->restricciones()
            ->where('status', 'ACTIVE')
            ->sum('restricted_amount');

        // Saldo real = Autorizado - Utilizado
        $saldoReal = bcsub($montoAutorizado, $saldoUtilizado, 2);
        
        // Saldo disponible = Saldo real - Restringido
        $saldoDisponible = bcsub($saldoReal, $montoRestringido, 2);
        
        // Si por alguna razón el saldo disponible es negativo, lo topamos a 0
        if (bccomp($saldoDisponible, '0.00', 2) < 0) {
            $saldoDisponible = '0.00';
        }

        return [
            'monto_autorizado' => $montoAutorizado,
            'saldo_utilizado' => $saldoUtilizado,
            'monto_restringido' => $montoRestringido,
            'saldo_disponible' => $saldoDisponible,
        ];
    }
}
