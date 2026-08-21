<?php

namespace App\Services\Credito;

use App\Models\RestriccionUsoCredito;

class EvaluadorReglaCincuenta
{
    /**
     * Evalúa la regla del 50% basándose en una restricción vigente y el saldo disponible actual.
     * Retorna los importes en string exacto a 4 decimales.
     */
    public function evaluar(?RestriccionUsoCredito $restriccion, string $availableBalance): array
    {
        $availableBalance = bcadd($availableBalance, '0.0000', 4);

        // Cuando no exista restricción vigente, devolver nulls y no inventar un rango.
        if (! $restriccion) {
            return [
                'restriction_id' => null,
                'restriction_type' => null,
                'restriction_status' => null,
                'base_total' => null,
                'available_balance' => $availableBalance,
                'tolerance_amount' => null,
                'reference_amount' => null,
                'lower_limit' => null,
                'upper_limit' => null,
                'has_admissible_range' => true, // Sin restricción, todo el saldo es admisible para un vale
            ];
        }

        // Utilizar la base total y tolerancia congeladas en la restricción
        $baseTotal = bcadd($restriccion->base_total, '0.0000', 4);
        $toleranceAmount = bcadd($restriccion->tolerance_amount, '0.0000', 4);

        // reference_amount = base_total * 0.50
        $referenceAmount = bcmul($baseTotal, '0.5000', 4);

        // lower_limit = 0.0000 (hasta el 50% + tolerancia)
        $lowerLimit = '0.0000';

        // temp_upper = reference_amount + tolerance_amount
        $tempUpper = bcadd($referenceAmount, $toleranceAmount, 4);

        // upper_limit = min(available_balance, reference_amount + tolerance_amount)
        $upperLimit = bccomp($availableBalance, $tempUpper, 4) < 0 ? $availableBalance : $tempUpper;

        // has_admissible_range = true cuando upper_limit >= lower_limit
        $hasAdmissibleRange = bccomp($upperLimit, $lowerLimit, 4) >= 0;

        return [
            'restriction_id' => $restriccion->id,
            'restriction_type' => $restriccion->type,
            'restriction_status' => $restriccion->status->value ?? $restriccion->status,
            'base_total' => $baseTotal,
            'available_balance' => $availableBalance,
            'tolerance_amount' => $toleranceAmount,
            'reference_amount' => $referenceAmount,
            'lower_limit' => $lowerLimit,
            'upper_limit' => $upperLimit,
            'has_admissible_range' => $hasAdmissibleRange,
        ];
    }
}
