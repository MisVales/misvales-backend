<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Support;

/** Metadatos técnicos de una escritura repetible o crítica. */
final readonly class OperationMetadata
{
    public function __construct(
        public string $idempotencyKey,
        public string $requestId,
        public ?string $traceId = null,
        public ?string $ipAddress = null,
        public ?string $device = null,
        public ?int $authSessionId = null,
    ) {}
}
