<?php

namespace App\Services\Credito;

use App\Models\SolicitudIncrementoLinea;

class GeneradorFolioSolicitudIncremento
{
    /**
     * Genera un folio único para la solicitud de incremento si se requiriera en el futuro.
     * Actualmente usamos UUID, por lo que esto retorna el UUID recortado o formateado.
     */
    public function generar(SolicitudIncrementoLinea $solicitud): string
    {
        // Ejemplo: "INC-2026-ABCD"
        $shortId = strtoupper(substr($solicitud->id, 0, 4));
        $year = date('Y');
        
        return "INC-{$year}-{$shortId}";
    }
}
