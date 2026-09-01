<?php

namespace App\Services\Vale;

use InvalidArgumentException;

final class CalculadorFinancieroVale
{
    public function calcular(string $capital, string $comision, string $interes, int $quincenas, string $seguro, string $ganancia): array
    {
        if ($quincenas < 1 || bccomp($capital, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException('Capital y quincenas deben ser positivos.');
        }

        // Los componentes se conservan con precisión interna. El redondeo se
        // hace una sola vez, sobre el total, nunca sobre cada componente.
        $capital = $this->normalizar($capital);
        $seguro = $this->normalizar($seguro);
        $comisionMonto = $this->normalizar(bcmul($capital, $comision, 12));
        $interesQuincena = $this->normalizar(bcmul($capital, $interes, 12));
        $interesTotal = $this->normalizar(bcmul($interesQuincena, (string) $quincenas, 12));
        $gananciaTotal = $this->normalizar(bcmul($capital, $ganancia, 12));

        $totalBaseExacto = $this->sumar([$capital, $comisionMonto, $seguro, $interesTotal]);
        $totalBase = $this->redondearTotal($totalBaseExacto);

        // MisVales = total base − ganancia de la distribuidora. El importe se
        // redondea después de sumar los componentes exactos.
        $totalMisVales = $this->redondearTotal(bcsub($totalBaseExacto, $gananciaTotal, 4));
        if (bccomp($totalMisVales, '0.0000', 4) < 0) {
            throw new InvalidArgumentException('La ganancia de categoría no puede exceder el pago base.');
        }

        $componentes = [
            'capital' => $this->distribuir($capital, $quincenas),
            'loan_commission' => $this->distribuir($comisionMonto, $quincenas),
            'interest' => $this->distribuir($interesTotal, $quincenas),
            'insurance' => $this->distribuir($seguro, $quincenas),
            'distributor_profit' => $this->distribuir($gananciaTotal, $quincenas),
            'misvales_payment' => $this->distribuir($totalMisVales, $quincenas),
            'client_payment' => $this->distribuir($totalBase, $quincenas),
        ];

        $parcialidades = [];
        for ($indice = 0; $indice < $quincenas; $indice++) {
            $parcialidades[] = ['number' => $indice + 1] + array_map(static fn (array $valores): string => $valores[$indice], $componentes);
        }

        return [
            'capital' => $capital,
            'loan_commission_percentage' => $this->tasa($comision),
            'loan_commission_amount' => $comisionMonto,
            'simple_interest_percentage' => $this->tasa($interes),
            'fortnights_count' => $quincenas,
            'insurance_amount' => $seguro,
            'interest_per_fortnight' => $interesQuincena,
            'interest_total' => $interesTotal,
            'misvales_total' => $totalMisVales,
            'misvales_payment_per_fortnight' => $componentes['misvales_payment'][0],
            'capital_per_fortnight' => $componentes['capital'][0],
            'distributor_profit_percentage' => $this->tasa($ganancia),
            'distributor_profit_total' => $gananciaTotal,
            'distributor_profit_per_fortnight' => $componentes['distributor_profit'][0],
            'net_payment_after_distributor_profit_per_fortnight' => $componentes['misvales_payment'][0],
            'client_payment_per_fortnight' => $componentes['client_payment'][0],
            'client_total' => $totalBase,
            'installments' => $parcialidades,
        ];
    }

    private function distribuir(string $total, int $partes): array
    {
        $base = $this->piso(bcdiv($total, (string) $partes, 10));
        $valores = array_fill(0, $partes, $base);
        $valores[$partes - 1] = bcsub($total, bcmul($base, (string) ($partes - 1), 4), 4);

        return $valores;
    }

    private function sumar(array $valores): string
    {
        return array_reduce($valores, static fn (string $total, string $valor): string => bcadd($total, $valor, 4), '0.0000');
    }

    private function tasa(string $valor): string
    {
        return bcadd($valor, '0.000000', 6);
    }

    private function normalizar(string $valor): string
    {
        return bcadd($valor, '0', 4);
    }

    /** La parcialidad base se expresa en pesos enteros; la última conserva el residuo. */
    private function piso(string $valor): string
    {
        return bcadd(bcdiv($valor, '1', 0), '0.0000', 4);
    }

    /** Redondeo aritmético al peso, conservando el formato monetario interno. */
    private function redondearTotal(string $valor): string
    {
        if (bccomp($valor, '0', 4) >= 0) {
            return bcadd($valor, '0.5', 0).'.0000';
        }

        return bcsub($valor, '0.5', 0).'.0000';
    }
}
