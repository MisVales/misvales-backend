<?php

namespace App\Services\SolicitudDistribuidora;

use Illuminate\Support\Facades\Crypt;

final class ProtectorDatosSolicitud
{
    public function normalizarCurp(string $curp): string
    {
        return $this->normalizarIdentificador($curp);
    }

    public function cifrarCurp(string $curp): string
    {
        return Crypt::encryptString($this->normalizarCurp($curp));
    }

    public function generarHmacCurp(string $curp): string
    {
        return $this->generarHmac($this->normalizarCurp($curp));
    }

    public function normalizarRfc(string $rfc): string
    {
        return $this->normalizarIdentificador($rfc);
    }

    public function cifrarRfc(string $rfc): string
    {
        return Crypt::encryptString($this->normalizarRfc($rfc));
    }

    public function generarHmacRfc(string $rfc): string
    {
        return $this->generarHmac($this->normalizarRfc($rfc));
    }

    public function cifrarIdentificacion(string $identificacion): string
    {
        return Crypt::encryptString($this->normalizarIdentificador($identificacion));
    }

    public function generarHmacIdentificacion(string $identificacion): string
    {
        return $this->generarHmac($this->normalizarIdentificador($identificacion));
    }

    public function descifrar(string $valor): string
    {
        return Crypt::decryptString($valor);
    }

    public function enmascarar(string $valor, int $inicio = 4, int $final = 6): string
    {
        $longitud = mb_strlen($valor);

        if ($longitud <= $inicio + $final) {
            return str_repeat('*', $longitud);
        }

        return mb_substr($valor, 0, $inicio)
            .str_repeat('*', $longitud - $inicio - $final)
            .mb_substr($valor, -$final);
    }

    private function normalizarIdentificador(string $valor): string
    {
        return mb_strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($valor)));
    }

    private function generarHmac(string $valor): string
    {
        return hash_hmac('sha256', $valor, (string) config('app.key'));
    }
}
