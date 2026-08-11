<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class ExcepcionCredito extends RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $estadoHttp = 400,
        public readonly array $campos = [],
        public readonly array $detalles = [],
    ) {
        parent::__construct($mensaje, $estadoHttp);
    }

    public function render($request): JsonResponse
    {
        return response()->json(['error' => [
            'code' => $this->codigo,
            'message' => $this->getMessage(),
            'fields' => $this->campos === [] ? (object) [] : $this->campos,
            'details' => $this->detalles === [] ? (object) [] : $this->detalles,
            'request_id' => $request->attributes->get('request_id'),
        ]], $this->estadoHttp);
    }
}
