<?php

namespace App\Services\Cliente;

use InvalidArgumentException;

final class NormalizadorCurp
{
    public function normalizar(string $curp): string
    {
        $normalizada = mb_strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($curp)));

        if (! preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $normalizada)) {
            throw new InvalidArgumentException('La CURP no tiene un formato válido.');
        }

        return $normalizada;
    }
}
