<?php

namespace App\Exceptions;

class BusinessException extends ApiException
{
    public function __construct(
        string $errorCode,
        string $message,
        int $statusCode = 400,
        ?\Exception $previous = null
    ) {
        parent::__construct($errorCode, $message, $statusCode, [], []);
    }
}
