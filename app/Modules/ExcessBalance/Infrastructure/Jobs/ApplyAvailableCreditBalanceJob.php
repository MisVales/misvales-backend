<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Jobs;

use App\Modules\ExcessBalance\Application\Services\ApplyCreditBalanceToNextRelation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ApplyAvailableCreditBalanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $eventId,
        public readonly string $relationId,
        public readonly int $distributorId,
        public readonly int $branchId,
    ) {
        $this->afterCommit();
    }

    public function handle(ApplyCreditBalanceToNextRelation $service): void
    {
        $service->execute($this->eventId, $this->relationId, $this->distributorId, $this->branchId);
    }
}
