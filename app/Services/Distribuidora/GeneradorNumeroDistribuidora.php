<?php

namespace App\Services\Distribuidora;

use Illuminate\Support\Facades\DB;

class GeneradorNumeroDistribuidora
{
    public function generar(?int $anio = null): string
    {
        $resultado = DB::selectFromWriteConnection('SELECT NEXT VALUE FOR distributor_number_seq AS value');
        $consecutivo = (int) ($resultado[0]->value ?? 1);

        return sprintf('DIS-%d-%06d', $anio ?? now()->year, $consecutivo);
    }
}
