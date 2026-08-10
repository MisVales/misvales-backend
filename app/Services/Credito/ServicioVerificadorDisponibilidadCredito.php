<?php

namespace App\Services\Credito;

use App\Contracts\Credito\ResultadoDisponibilidadCredito;
use App\Contracts\Credito\VerificadorDisponibilidadCredito;
use App\Models\LineaCredito;
use App\Models\RestriccionUsoCredito;

class ServicioVerificadorDisponibilidadCredito implements VerificadorDisponibilidadCredito
{
    protected CalculadorSaldoCredito $calculadorSaldo;
    protected EvaluadorReglaCincuenta $evaluadorRegla;

    public function __construct(
        CalculadorSaldoCredito $calculadorSaldo,
        EvaluadorReglaCincuenta $evaluadorRegla
    ) {
        $this->calculadorSaldo = $calculadorSaldo;
        $this->evaluadorRegla = $evaluadorRegla;
    }

    public function evaluar(
        string $distribuidoraId,
        string $capitalProducto,
        ?string $valeId = null
    ): ResultadoDisponibilidadCredito {
        // 1. Encontrar la línea activa de la distribuidora
        $linea = LineaCredito::where('distributor_id', $distribuidoraId)->firstOrFail();

        // 2. Calcular los saldos en vivo (previene errores flotantes o desfases)
        $saldos = $this->calculadorSaldo->calcular($linea->total_authorized, $linea->used_balance);
        $totalAuthorized = $saldos['total_authorized'];
        $usedBalance = $saldos['used_balance'];
        $availableBalance = $saldos['available_balance'];

        // 3. Evaluar disponibilidad básica
        // capital_is_available = true si available_balance >= capitalProducto
        $capitalIsAvailable = bccomp($availableBalance, $capitalProducto, 4) >= 0;

        // 4. Buscar restricción del 50% activa (o reservada para este mismo vale)
        $restriccion = null;
        
        $query = RestriccionUsoCredito::where('credit_line_id', $linea->id);
        
        if ($valeId) {
            $query->where(function ($q) use ($valeId) {
                $q->where('status', 'ACTIVE')
                  ->orWhere(function ($q2) use ($valeId) {
                      $q2->where('status', 'RESERVED')
                         ->where('reserved_voucher_id', $valeId);
                  });
            });
        } else {
            $query->where('status', 'ACTIVE');
        }

        $restriccion = $query->first();

        // 5. Analizar regla del 50%
        $hasActiveRestriction = false;
        $restrictionId = null;
        $lowerLimit = null;
        $upperLimit = null;
        $capitalSatisfiesRestriction = true; // Por defecto cumple si no hay restricción

        if ($restriccion) {
            $hasActiveRestriction = true;
            $restrictionId = $restriccion->id;

            // Evaluamos el rango matemático de esta restricción
            $evaluacion = $this->evaluadorRegla->evaluar(
                $restriccion,
                $availableBalance
            );

            $lowerLimit = $evaluacion['lower_limit'];
            $upperLimit = $evaluacion['upper_limit'];

            // ¿El capital cae dentro del rango admisible de la restricción?
            // capitalProducto >= lowerLimit AND capitalProducto <= upperLimit
            $cumpleMinimo = bccomp($capitalProducto, $lowerLimit, 4) >= 0;
            $cumpleMaximo = bccomp($capitalProducto, $upperLimit, 4) <= 0;

            $capitalSatisfiesRestriction = $cumpleMinimo && $cumpleMaximo;
        }

        return new ResultadoDisponibilidadCredito(
            credit_line_id: $linea->id,
            total_authorized: $totalAuthorized,
            used_balance: $usedBalance,
            available_balance: $availableBalance,
            has_active_restriction: $hasActiveRestriction,
            restriction_id: $restrictionId,
            lower_limit: $lowerLimit,
            upper_limit: $upperLimit,
            capital_is_available: $capitalIsAvailable,
            capital_satisfies_restriction: $capitalSatisfiesRestriction
        );
    }
}
