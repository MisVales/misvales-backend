<?php

namespace App\Services\Credito;

use Illuminate\Support\Facades\DB;

class GeneradorFolioIncremento
{
    public function generar(?int $anio = null): string
    {
        $consecutivo = (int) DB::scalar("SELECT nextval('credit_increase_request_seq')");

        return sprintf('INC-%d-%06d', $anio ?? now()->year, $consecutivo);
    }
}
