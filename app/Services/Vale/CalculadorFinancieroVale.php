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

        // Entradas: piso solo a capital y seguro (normalización de moneda)
        $capital = $this->piso($capital);
        $seguro = $this->piso($seguro);

        // El interés total debe calcularse con la tasa sin redondear la cuota
        // primero; de lo contrario, la multiplicación por las quincenas puede
        // introducir diferencias de un peso (o de centavos).
        $comisionMonto = $this->redondear(bcmul($capital, $comision, 10));
        $interesCalculado = bcmul($capital, $interes, 10);
        $interesQuincena = $this->redondear($interesCalculado);
        $interesTotal = $this->redondear(bcmul($interesCalculado, (string) $quincenas, 10));
        $gananciaTotal = $this->redondear(bcmul($capital, $ganancia, 10));

        // Deuda total = round(P + CE + S + IT, 2)
        $totalBase = $this->redondear($this->sumar([$capital, $comisionMonto, $seguro, $interesTotal]));
        // La base es el importe exigible a MisVales. La ganancia pertenece a
        // la distribuidora y se suma únicamente al total que paga el cliente.
        $totalMisVales = $totalBase;
        $totalCliente = bcadd($totalBase, $gananciaTotal, 4);

        $capitales = $this->distribuir($capital, $quincenas);
        $comisiones = $this->distribuir($comisionMonto, $quincenas);
        $intereses = $this->distribuir($interesTotal, $quincenas);
        $seguros = $this->distribuir($seguro, $quincenas);
        $ganancias = $this->distribuir($gananciaTotal, $quincenas);
        $pagosMisVales = $this->distribuir($totalMisVales, $quincenas);

        // El cobro al cliente se obtiene por parcialidad (base + ganancia),
        // para que el residuo de cada componente no genere un peso perdido.
        $pagosCliente = array_map(
            static fn (string $base, string $ganancia): string => bcadd($base, $ganancia, 4),
            $pagosMisVales,
            $ganancias,
        );

        $componentes = [
            'capital' => $capitales,
            'loan_commission' => $comisiones,
            'interest' => $intereses,
            'insurance' => $seguros,
            'distributor_profit' => $ganancias,
            'misvales_payment' => $pagosMisVales,
            'client_payment' => $pagosCliente,
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
            'client_total' => $totalCliente,
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

    /** Redondeo matemático al centavo (2 decimales), formateado a 4. */
    private function redondear(string $valor): string
    {
        if (bccomp($valor, '0', 10) >= 0) {
            return bcadd(bcadd($valor, '0.005', 2), '0.0000', 4);
        }

        return bcadd(bcsub($valor, '0.005', 2), '0.0000', 4);
    }

    /** Truncamiento al peso entero, formateado a 4 decimales. */
    private function piso(string $valor): string
    {
        return bcadd(bcdiv($valor, '1', 0), '0.0000', 4);
    }
}
