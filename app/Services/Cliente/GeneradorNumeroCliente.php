<?php

namespace App\Services\Cliente;

use Illuminate\Support\Facades\DB;

final class GeneradorNumeroCliente
{
    public function generar(?int $anio = null): string
    {
        $consecutivo = (int) DB::scalar("SELECT nextval('client_number_seq')");

        return sprintf('CLI-%d-%06d', $anio ?? now()->year, $consecutivo);
    }
}
