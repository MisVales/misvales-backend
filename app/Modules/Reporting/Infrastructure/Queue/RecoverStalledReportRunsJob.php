<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Queue;

use App\Modules\Reporting\Domain\Enums\ReportRunStatus;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRun;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRunResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RecoverStalledReportRunsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $cutoff = now('UTC')->subMinutes((int) config('reporting.stalled_after_minutes', 30));
        ReportRun::query()
            ->where('status', ReportRunStatus::RUNNING)
            ->where('started_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($runs) use ($cutoff): void {
                foreach ($runs as $run) {
                    $lock = Cache::lock(ExecuteReportRunJob::lockName($run->id), 60);
                    if (! $lock->get()) {
                        continue;
                    }
                    try {
                        $requeued = DB::transaction(function () use ($run, $cutoff): bool {
                            $locked = ReportRun::query()->lockForUpdate()->find($run->id);
                            if ($locked === null
                                || $locked->status !== ReportRunStatus::RUNNING
                                || $locked->started_at === null
                                || $locked->started_at->greaterThan($cutoff)) {
                                return false;
                            }
                            ReportRunResult::query()->where('report_run_id', $locked->id)->delete();
                            $locked->status = ReportRunStatus::QUEUED;
                            $locked->started_at = null;
                            $locked->error_code = null;
                            $locked->save();

                            return true;
                        });
                    } finally {
                        $lock->release();
                    }
                    if ($requeued) {
                        ExecuteReportRunJob::dispatch($run->id);
                    }
                }
            }, 'id');
    }
}
