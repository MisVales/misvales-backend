<?php

namespace App\Exceptions;

final class ExcepcionExcedente extends ApiException
{
    public function __construct(string $codigo, string $mensaje, int $estadoHttp = 409, array $campos = [], array $detalles = [])
    {
        parent::__construct($codigo, $mensaje, $estadoHttp, $campos, $detalles);
    }
}
