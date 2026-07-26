<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Jobs;

use App\Modules\Points\Application\Services\RedemptionPeriodService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CloseExpiredRedemptionPeriodsJob implements ShouldQueue
{
    use Queueable;

    public function handle(RedemptionPeriodService $periods): void
    {
        $periods->closeExpired();
    }
}
