<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Jobs;

use App\Modules\RiskDelinquency\Application\Services\DetectFinancialRegularization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DetectFinancialRegularizationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $distributorId, public readonly string $sourceVersion) {}

    public function handle(DetectFinancialRegularization $service): void
    {
        $service->detect($this->distributorId, $this->sourceVersion);
    }
}
