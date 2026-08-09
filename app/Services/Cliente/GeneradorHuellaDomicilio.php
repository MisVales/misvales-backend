<?php

namespace App\Services\Cliente;

final class GeneradorHuellaDomicilio
{
    public function __construct(private readonly NormalizadorDomicilio $normalizador) {}

    public function generar(array $domicilio): string
    {
        return hash_hmac('sha256', $this->normalizador->serializar($domicilio), $this->clave());
    }

    private function clave(): string
    {
        $clave = (string) config('clientes.hmac_key');
        if ($clave === '') {
            throw new \RuntimeException('CLIENT_DATA_HMAC_KEY no está configurada.');
        }

        return $clave;
    }
}
