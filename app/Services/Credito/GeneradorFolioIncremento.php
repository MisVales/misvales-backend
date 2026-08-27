<?php

namespace App\Services\Credito;

use Illuminate\Support\Facades\DB;

class GeneradorFolioIncremento
{
    public function generar(?int $anio = null): string
    {
        $resultado = DB::selectFromWriteConnection('SELECT NEXT VALUE FOR credit_increase_request_seq AS value');
        $consecutivo = (int) ($resultado[0]->value ?? 1);

        return sprintf('INC-%d-%06d', $anio ?? now()->year, $consecutivo);
    }
}
