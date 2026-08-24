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

        $capital = $this->piso($capital);
        $seguro = $this->piso($seguro);
        $comisionMonto = $this->redondear(bcmul($capital, $comision, 10));
        $interesQuincena = $this->redondear(bcmul($capital, $interes, 10));
        $interesTotal = $this->redondear(bcmul(bcmul($capital, $interes, 10), (string) $quincenas, 10));
        $totalMisVales = $this->sumar([$capital, $comisionMonto, $seguro, $interesTotal]);
        $gananciaTotal = $this->redondear(bcmul($capital, $ganancia, 10));

        $componentes = [
            'capital' => $this->distribuir($capital, $quincenas),
            'loan_commission' => $this->distribuir($comisionMonto, $quincenas),
            'interest' => $this->distribuir($interesTotal, $quincenas),
            'insurance' => $this->distribuir($seguro, $quincenas),
            'distributor_profit' => $this->distribuir($gananciaTotal, $quincenas),
            'misvales_payment' => $this->distribuir($totalMisVales, $quincenas),
            'client_payment' => $this->distribuir($totalMisVales, $quincenas),
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
            'net_payment_after_distributor_profit_per_fortnight' => bcsub($componentes['misvales_payment'][0], $componentes['distributor_profit'][0], 4),
            'client_payment_per_fortnight' => $componentes['client_payment'][0],
            'client_total' => $totalMisVales,
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

    private function redondear(string $valor): string
    {
        return $this->piso($valor);
    }

    private function piso(string $valor): string
    {
        return bcadd(bcdiv($valor, '1', 0), '0.0000', 4);
    }
}
