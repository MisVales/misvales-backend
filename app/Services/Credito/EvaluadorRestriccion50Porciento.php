<?php

namespace App\Services\Credito;

class EvaluadorRestriccion50Porciento
{
    public function evaluar(string $baseTotal, string $disponibleReal, string $tolerancia): array
    {
        $referencia = bcdiv($baseTotal, '2', 4);

        $limiteInferior = bcsub($referencia, $tolerancia, 4);
        $limiteSuperiorNominal = bcadd($referencia, $tolerancia, 4);

        $limiteSuperiorReal = bccomp($limiteSuperiorNominal, $disponibleReal, 4) > 0
            ? $disponibleReal
            : $limiteSuperiorNominal;

        $admisible = bccomp($limiteSuperiorReal, $limiteInferior, 4) >= 0;

        return [
            'referencia' => $referencia,
            'limite_inferior' => $limiteInferior,
            'limite_superior_nominal' => $limiteSuperiorNominal,
            'limite_superior_real' => $limiteSuperiorReal,
            'admisible' => $admisible,
        ];
    }
}
