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
            $configuracionTolerancia = app(\App\Services\ConfiguracionServicio::class)->resolver('CREDIT_TOLERANCE_AMOUNT');

            return RestriccionUsoCredito::create([
                'credit_line_id' => $linea->id,
                'distributor_id' => $linea->distributor_id,
                'type' => 'POST_INCREASE_50_PERCENT',
                'status' => \App\Enums\EstadoRestriccionUsoCredito::ACTIVE,
                'base_total' => $montoRestringido,
                'tolerance_amount' => $configuracionTolerancia['value'],
                'configuration_version_id' => $configuracionTolerancia['version_id'],
                'source_type' => 'CREDIT_INCREASE_REQUEST',
                'source_id' => $solicitudId,
                'activated_at' => now(),
                'created_by' => auth()->id(),
            ]);
        });
    }
}
