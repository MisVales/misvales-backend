<?php

namespace App\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $fields = [],
        public readonly array $details = []
    ) {
        parent::__construct($message, $httpStatus);
    }

}
