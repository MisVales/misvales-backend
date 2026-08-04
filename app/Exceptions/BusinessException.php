<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class BusinessException extends Exception
{
    public string $errorCode;

    public function __construct(string $errorCode, string $message, int $statusCode = 400, ?Exception $previous = null)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message, $statusCode, $previous);
    }

    public function render($request): JsonResponse
    {
        return response()->json([
            'error' => $this->errorCode,
            'message' => $this->getMessage()
        ], $this->getCode());
    }
}
