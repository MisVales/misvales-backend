<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

/** Metadatos protegibles de una mutación, aislados de HTTP. */
final readonly class OperationMetadata
{
    public function __construct(
        public string $requestId,
        public string $idempotencyKey,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
