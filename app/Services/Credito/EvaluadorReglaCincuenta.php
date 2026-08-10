<?php

namespace App\Services\Credito;

use App\Models\LineaCredito;

class EvaluadorReglaCincuenta
{
    protected CalculadorSaldoCredito $calculador;

    public function __construct(CalculadorSaldoCredito $calculador)
    {
        $this->calculador = $calculador;
    }

    /**
     * Evalúa si un monto específico puede ser utilizado basado en el saldo disponible actual
     * que ya tiene descontadas las restricciones del 50%.
     */
    public function puedeUtilizar(LineaCredito $linea, string $montoSolicitado): bool
    {
        $saldos = $this->calculador->calcular($linea);
        
        $saldoDisponible = $saldos['saldo_disponible'];

        return bccomp($saldoDisponible, $montoSolicitado, 2) >= 0;
    }
}
