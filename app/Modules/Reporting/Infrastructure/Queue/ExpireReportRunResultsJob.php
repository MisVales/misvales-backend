<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Queue;

use App\Modules\Reporting\Application\Services\ReportRunService;
use App\Modules\Reporting\Domain\Enums\ReportRunStatus;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExpireReportRunResultsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(ReportRunService $runs): void
    {
        ReportRun::query()
            ->whereIn('status', [ReportRunStatus::COMPLETED, ReportRunStatus::FAILED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now('UTC'))
            ->orderBy('id')
            ->chunkById(100, function ($expired) use ($runs): void {
                foreach ($expired as $run) {
                    $runs->expire($run);
                }
            }, 'id');
    }
}
