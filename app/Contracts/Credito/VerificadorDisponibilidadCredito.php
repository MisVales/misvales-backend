<?php

namespace App\Contracts\Credito;

interface VerificadorDisponibilidadCredito
{
    /**
     * Evalúa si una distribuidora tiene línea de crédito suficiente y si cumple con
     * la restricción de crédito del 50% (si está activa).
     *
     * @param  string  $distribuidoraId  ID de la distribuidora
     * @param  string  $capitalProducto  Capital o importe solicitado en el vale a evaluar
     * @param  string|null  $valeId  ID del vale (opcional, para reintentos si la restricción ya está en RESERVED por este vale)
     */
    public function evaluar(
        string $distribuidoraId,
        string $capitalProducto,
        ?string $valeId = null
    ): ResultadoDisponibilidadCredito;
}
