<?php

namespace App\Services\Distribuidora;

use Illuminate\Support\Facades\DB;

class GeneradorNumeroDistribuidora
{
    public function generar(?int $anio = null): string
    {
        $consecutivo = (int) DB::scalar("SELECT nextval('distributor_number_seq')");

        return sprintf('DIS-%d-%06d', $anio ?? now()->year, $consecutivo);
    }
}
