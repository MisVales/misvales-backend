<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Services;

use App\Models\User;
use App\Modules\Credit\Domain\Aggregates\CreditLine;
use App\Modules\Credit\Domain\Enums\CreditMovementType;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Mappers\CreditLineMapper;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineMovementModel;

final readonly class CreditMovementService
{
    public function __construct(private CreditLineMapper $mapper) {}

    /** @param array<string, mixed>|null $configuration */
    public function append(
        CreditLineModel $lineModel,
        CreditLine $before,
        CreditLine $after,
        CreditMovementType $type,
        string $sourceType,
        string $sourceId,
        ?User $actor,
        ?int $authorizedByUserId,
        int $branchId,
        string $reason,
        string $idempotencyKey,
        ?array $configuration = null,
    ): CreditLineMovementModel {
        $movement = CreditLineMovementModel::query()->create([
            'credit_line_id' => $lineModel->id,
            'type' => $type,
            'total_delta' => $after->totalAuthorized->subtract($before->totalAuthorized)->databaseValue(),
            'used_delta' => $after->usedBalance->subtract($before->usedBalance)->databaseValue(),
            'total_before' => $before->totalAuthorized->databaseValue(),
            'total_after' => $after->totalAuthorized->databaseValue(),
            'used_before' => $before->usedBalance->databaseValue(),
            'used_after' => $after->usedBalance->databaseValue(),
            'available_before' => $before->availableBalance()->databaseValue(),
            'available_after' => $after->availableBalance()->databaseValue(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'actor_user_id' => $actor?->id,
            'authorized_by_user_id' => $authorizedByUserId,
            'branch_id' => $branchId,
            'reason' => $reason,
            'configuration_snapshot' => $configuration,
            'occurred_at' => now('UTC'),
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->mapper->apply($after, $lineModel);
        $lineModel->last_movement_id = $movement->id;
        $lineModel->lock_version = ((int) $lineModel->lock_version) + 1;
        $lineModel->save();

        return $movement;
    }
}
