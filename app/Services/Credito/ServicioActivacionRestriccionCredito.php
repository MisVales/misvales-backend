<?php

namespace App\Services\Credito;

use App\Models\LineaCredito;
use App\Models\RestriccionUsoCredito;
use Illuminate\Support\Facades\DB;

class ServicioActivacionRestriccionCredito
{
    public function aplicarRestriccion(LineaCredito $linea, string $montoIncremento, string $solicitudId): RestriccionUsoCredito
    {
        return DB::transaction(function () use ($linea, $montoIncremento, $solicitudId) {
            $montoRestringido = bcmul($montoIncremento, '0.50', 2);

            return RestriccionUsoCredito::create([
                'credit_line_id' => $linea->id,
                'type' => 'INCREASE_' . substr($solicitudId, 0, 36),
                'base_total' => $montoRestringido,
                'status' => \App\Enums\EstadoRestriccionUsoCredito::ACTIVE,
            ]);
        });
    }
}
