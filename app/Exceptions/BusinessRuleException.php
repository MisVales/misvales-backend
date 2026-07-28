<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class BusinessRuleException extends Exception
{
    protected string $errorCode;
    protected int $statusCode;
    protected array $fields;
    protected array $details;

    public function __construct(
        string $errorCode, 
        string $message, 
        int $statusCode = 400, 
        array $fields = [], 
        array $details = []
    ) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->fields = $fields;
        $this->details = $details;
    }

    /**
     * Renderiza la excepción de dominio al formato exacto requerido por O11.
     */
    public function render($request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code'       => $this->errorCode,
                'message'    => $this->getMessage(),
                // Se hace cast a (object) para que si el array está vacío en JSON salga como {} en vez de []
                'fields'     => (object) $this->fields,
                'details'    => (object) $this->details,
                // Si tienes un middleware que inyecta request_id úsalo, sino generamos un UUID por defecto.
                'request_id' => $request->header('X-Request-ID') ?? (string) Str::uuid()
            ]
        ], $this->statusCode);
    }
}