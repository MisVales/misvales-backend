<?php

namespace App\Exceptions;

final class ExcepcionConciliacion extends ApiException
{
    public function __construct(
        string $codigo,
        string $mensaje,
        int $estadoHttp = 422,
        array $campos = [],
        array $detalles = []
    ) {
        parent::__construct($codigo, $mensaje, $estadoHttp, $campos, $detalles);
    }
}
