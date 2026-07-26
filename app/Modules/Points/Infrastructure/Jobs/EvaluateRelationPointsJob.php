<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Jobs;

use App\Modules\Points\Application\DTOs\RelationLiquidationSnapshot;
use App\Modules\Points\Application\Services\EvaluateRelationPoints;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Consumidor reintentable del evento definitivo de M11. */
final class EvaluateRelationPointsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly RelationLiquidationSnapshot $snapshot) {}

    public function handle(EvaluateRelationPoints $evaluator): void
    {
        $evaluator->execute($this->snapshot);
    }
}
