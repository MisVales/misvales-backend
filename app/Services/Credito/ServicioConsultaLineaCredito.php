<?php

namespace App\Services\Credito;

use App\Models\LineaCredito;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ServicioConsultaLineaCredito
{
    protected CalculadorSaldoCredito $calculador;

    public function __construct(CalculadorSaldoCredito $calculador)
    {
        $this->calculador = $calculador;
    }

    public function consultarPorDistribuidora(User $distribuidora): array
    {
        $linea = LineaCredito::where('distributor_id', $distribuidora->id)->first();
        
        if (!$linea) {
            throw new ModelNotFoundException("La distribuidora no tiene una línea de crédito asignada.");
        }

        $saldos = $this->calculador->calcular($linea);

        return [
            'id' => $linea->id,
            'distributor_id' => $linea->distributor_id,
            'saldos' => $saldos,
            'restricciones_activas' => $linea->restricciones()->where('status', 'ACTIVE')->get(),
        ];
    }
}
