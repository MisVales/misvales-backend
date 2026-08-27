<?php

namespace App\Services\SolicitudDistribuidora;

use Illuminate\Support\Facades\DB;

final class GeneradorFolioSolicitud
{
    public function generar(): string
    {
        $resultado = DB::selectFromWriteConnection('SELECT NEXT VALUE FOR distributor_application_number_seq AS value');

        return sprintf('SOL-%s-%06d', now()->format('Y'), (int) ($resultado[0]->value ?? 1));
    }
}
