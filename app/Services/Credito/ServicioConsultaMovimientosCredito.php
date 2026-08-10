<?php

namespace App\Services\Credito;

use App\Models\LineaCredito;

class ServicioConsultaMovimientosCredito
{
    public function consultar(LineaCredito $linea, int $perPage = 15)
    {
        return $linea->movimientos()->orderBy('created_at', 'desc')->paginate($perPage);
    }
}
