<?php

namespace App\Exceptions;

final class ExcepcionVale extends ApiException
{
    public function __construct(
        string $codigo,
        string $mensaje,
        int $estadoHttp = 400,
        array $detalles = []
    ) {
        parent::__construct($codigo, $mensaje, $estadoHttp, [], $detalles);
    }
}
