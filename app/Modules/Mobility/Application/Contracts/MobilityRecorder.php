<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Application\Contracts;

use App\Models\User;

/** Auditoría funcional e inserción transaccional de outbox. */
interface MobilityRecorder
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function audit(
        string $event,
        string $aggregateType,
        string $aggregateId,
        User $actor,
        ?int $branchId,
        string $result,
        ?string $reason,
        array $before = [],
        array $after = [],
    ): void;

    /** @param array<string, mixed> $payload */
    public function outbox(
        string $event,
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        ?string $causationId,
        array $payload,
    ): void;

    public function history(
        string $aggregateType,
        string $aggregateId,
        ?string $previousState,
        string $newState,
        User $actor,
        ?int $branchId,
        string $useCase,
        ?string $reason,
        string $correlationId,
    ): void;
}
