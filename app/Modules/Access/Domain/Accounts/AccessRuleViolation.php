<?php

namespace App\Modules\Access\Domain\Accounts;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class AccessRuleViolation extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
