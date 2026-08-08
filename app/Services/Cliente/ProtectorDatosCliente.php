<?php

namespace App\Services\Cliente;

use Illuminate\Support\Facades\Crypt;

final class ProtectorDatosCliente
{
    public function __construct(private readonly NormalizadorCurp $normalizadorCurp) {}

    public function cifrar(string $valor): string
    {
        return Crypt::encryptString($valor);
    }

    public function descifrar(string $valor): string
    {
        return Crypt::decryptString($valor);
    }

    public function cifrarCurp(string $curp): string
    {
        return $this->cifrar($this->normalizadorCurp->normalizar($curp));
    }

    public function hmacCurp(string $curp): string
    {
        return $this->hmac($this->normalizadorCurp->normalizar($curp));
    }

    public function hmacExacto(string $valor): string
    {
        return $this->hmac(mb_strtoupper(trim($valor)));
    }

    public function enmascarar(string $valor, int $inicio = 4, int $final = 4): string
    {
        $longitud = mb_strlen($valor);
        if ($longitud <= $inicio + $final) {
            return str_repeat('*', $longitud);
        }

        return mb_substr($valor, 0, $inicio).str_repeat('*', $longitud - $inicio - $final).mb_substr($valor, -$final);
    }

    public function ultimosCuatro(string $valor): string
    {
        return str_repeat('*', max(0, mb_strlen($valor) - 4)).mb_substr($valor, -4);
    }

    private function hmac(string $valor): string
    {
        $clave = (string) config('clientes.hmac_key');
        if ($clave === '') {
            throw new \RuntimeException('CLIENT_DATA_HMAC_KEY no está configurada.');
        }

        return hash_hmac('sha256', $valor, $clave);
    }
}
