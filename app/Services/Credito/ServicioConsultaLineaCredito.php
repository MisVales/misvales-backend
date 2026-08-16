<?php

namespace App\Services\Credito;

use App\Models\Distribuidora;
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

    public function consultarPorDistribuidora(User $usuario): LineaCredito
    {
        $distribuidora = Distribuidora::query()->where('user_id', $usuario->id)->first();
        $linea = $distribuidora === null
            ? null
            : LineaCredito::query()
                ->with(['distribuidora.usuario', 'restricciones', 'movimientos'])
                ->where('distributor_id', $distribuidora->id)
                ->first();

        if (! $linea) {
            throw new ModelNotFoundException('La distribuidora no tiene una línea de crédito asignada.');
        }

        return $linea;
    }
}
