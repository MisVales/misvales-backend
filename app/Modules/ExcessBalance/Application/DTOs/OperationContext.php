<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\DTOs;

use App\Models\User;

final readonly class OperationContext
{
    public function __construct(
        public User $actor,
        public string $idempotencyKey,
        public string $correlationId,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
