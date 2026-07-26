<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Jobs;

use App\Modules\ExcessBalance\Application\Services\DetectExcessInconsistency;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReconcileExcessLedgerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $excessBalanceId)
    {
        $this->afterCommit();
    }

    public function handle(DetectExcessInconsistency $service): void
    {
        $service->execute($this->excessBalanceId);
    }
}
