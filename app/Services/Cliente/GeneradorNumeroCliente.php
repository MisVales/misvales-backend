<?php

namespace App\Services\Cliente;

use Illuminate\Support\Facades\DB;

final class GeneradorNumeroCliente
{
    public function generar(?int $anio = null): string
    {
        $resultado = DB::selectFromWriteConnection('SELECT NEXT VALUE FOR client_number_seq AS value');
        $consecutivo = (int) ($resultado[0]->value ?? 1);

        return sprintf('CLI-%d-%06d', $anio ?? now()->year, $consecutivo);
    }
}
