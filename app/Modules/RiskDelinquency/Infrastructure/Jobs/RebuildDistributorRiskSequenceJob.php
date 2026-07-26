<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Jobs;

use App\Modules\RiskDelinquency\Application\Services\RebuildDistributorRiskSequence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RebuildDistributorRiskSequenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $distributorId, public readonly string $reason) {}

    public function handle(RebuildDistributorRiskSequence $service): void
    {
        $service->rebuild($this->distributorId, $this->reason);
    }
}
