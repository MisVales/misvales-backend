<?php

namespace App\Exceptions;

class ExcepcionCliente extends ApiException
{
    public function __construct(
        string $codigo,
        string $mensaje,
        int $estadoHttp,
        array $campos = [],
        array $detalles = []
    ) {
        parent::__construct($codigo, $mensaje, $estadoHttp, $campos, $detalles);
    }
}
