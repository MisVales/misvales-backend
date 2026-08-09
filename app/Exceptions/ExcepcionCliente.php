<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class ExcepcionCliente extends RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $estadoHttp,
        public readonly array $campos = [],
        public readonly array $detalles = [],
    ) {
        parent::__construct($mensaje);
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
