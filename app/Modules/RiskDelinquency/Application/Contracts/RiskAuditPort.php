<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Contracts;

use App\Models\User;

interface RiskAuditPort
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        string $resourceType,
        string $resourceId,
        int $distributorId,
        ?int $branchId,
        ?User $actor = null,
        array $before = [],
        array $after = [],
        array $metadata = [],
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): void;
}
