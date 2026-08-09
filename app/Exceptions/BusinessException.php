<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class BusinessException extends HttpException
{
    public string $errorCode;

    public int $statusCode;

    public function __construct(string $errorCode, string $message, int $statusCode = 400, ?Throwable $previous = null)
    {
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        parent::__construct($statusCode, $message, $previous);
    }
}
