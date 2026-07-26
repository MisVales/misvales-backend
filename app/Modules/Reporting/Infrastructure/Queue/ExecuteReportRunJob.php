<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Queue;

use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Reporting\Application\Services\ReportAuditRecorder;
use App\Modules\Reporting\Application\Services\ReportRegistry;
use App\Modules\Reporting\Application\Services\ReportResultProtector;
use App\Modules\Reporting\Domain\Contracts\ReportReadModelGateway;
use App\Modules\Reporting\Domain\Contracts\ReportResultStoreInterface;
use App\Modules\Reporting\Domain\Enums\ReportEventName;
use App\Modules\Reporting\Domain\Enums\ReportRunStatus;
use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use App\Modules\Reporting\Domain\ValueObjects\ReportResult;
use App\Modules\Reporting\Domain\ValueObjects\ReportScope;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRun;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ExecuteReportRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $runId) {}

    public function handle(
        ReportRegistry $registry,
        ReportReadModelGateway $gateway,
        ReportAuditRecorder $audit,
        ReportResultStoreInterface $results,
        ReportResultProtector $protector,
    ): void {
        $lock = Cache::lock(
            self::lockName($this->runId),
            ((int) config('reporting.stalled_after_minutes', 30) * 60) + 300,
        );
        if (! $lock->get()) {
            return;
        }
        try {
            $this->execute($registry, $gateway, $audit, $results, $protector);
        } finally {
            $lock->release();
        }
    }

    public static function lockName(string $runId): string
    {
        return 'reporting:run:'.$runId;
    }

    private function execute(
        ReportRegistry $registry,
        ReportReadModelGateway $gateway,
        ReportAuditRecorder $audit,
        ReportResultStoreInterface $results,
        ReportResultProtector $protector,
    ): void {
        $run = DB::transaction(function () use ($registry, $audit): ?ReportRun {
            $locked = ReportRun::query()->with('requester.role.permissions')->lockForUpdate()->find($this->runId);
            if ($locked === null || $locked->status !== ReportRunStatus::QUEUED) {
                return null;
            }
            $definition = $registry->get($locked->report_code);
            $scope = ReportScope::fromArray($locked->scope_snapshot);
            $locked->status = ReportRunStatus::RUNNING;
            $locked->started_at = now('UTC');
            $locked->save();
            $audit->outbox(
                ReportEventName::RUN_STARTED,
                $locked->id,
                $definition,
                $locked->requester,
                $scope,
                $locked->correlation_id,
                ['filters_hash' => $locked->filters_hash],
            );

            return $locked;
        });
        if ($run === null) {
            return;
        }

        try {
            $run->requester->refresh();
            if ($run->requester->state !== AccountState::ACTIVE) {
                throw ReportingException::accessDenied();
            }
            $definition = $registry->get($run->report_code);
            $scope = ReportScope::fromArray($run->scope_snapshot);
            $sort = $definition->sortableFields[0];
            $blockSize = (int) config('reporting.maximum_page_size', 100);
            $rowCount = 0;
            $asOf = null;
            $blockNumber = 0;
            foreach ($gateway->executeRun(
                $definition->code,
                $scope,
                $run->filters_json,
                $sort,
                'asc',
                $blockSize,
            ) as $result) {
                $result = $protector->protect($definition, $result);
                $asOf ??= $result->asOf;
                if ($asOf != $result->asOf || count($result->rows) > $blockSize) {
                    throw ReportingException::invalidRunState();
                }
                $results->storeBlock($run->id, $blockNumber, $result);
                $rowCount += count($result->rows);
                $blockNumber++;
            }
            $asOf ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($blockNumber === 0) {
                $results->storeBlock(
                    $run->id,
                    0,
                    new ReportResult([], [], ['total' => 0], $asOf),
                );
            }
            $asOf = CarbonImmutable::instance($asOf);

            DB::transaction(function () use ($run, $definition, $scope, $rowCount, $asOf, $audit): void {
                $locked = ReportRun::query()->with('requester')->lockForUpdate()->findOrFail($run->id);
                if ($locked->status !== ReportRunStatus::RUNNING) {
                    return;
                }
                $locked->status = ReportRunStatus::COMPLETED;
                $locked->completed_at = now('UTC');
                $locked->as_of = $asOf;
                $locked->row_count = $rowCount;
                $locked->result_location = 'database:report_run_results';
                $locked->save();
                $audit->allowed(
                    $locked->requester,
                    $definition,
                    $scope,
                    $locked->filters_json,
                    $rowCount,
                    $locked->correlation_id,
                    $locked->id,
                );
                $audit->outbox(
                    ReportEventName::RUN_COMPLETED,
                    $locked->id,
                    $definition,
                    $locked->requester,
                    $scope,
                    $locked->correlation_id,
                    ['filters_hash' => $locked->filters_hash, 'row_count' => $rowCount],
                );
            });
        } catch (Throwable $exception) {
            $this->failSafely($run, $registry, $audit, $results, $exception);
        }
    }

    private function failSafely(
        ReportRun $run,
        ReportRegistry $registry,
        ReportAuditRecorder $audit,
        ReportResultStoreInterface $results,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($run, $registry, $audit, $results, $exception): void {
            $locked = ReportRun::query()->with('requester')->lockForUpdate()->find($run->id);
            if ($locked === null || $locked->status !== ReportRunStatus::RUNNING) {
                return;
            }
            $errorCode = $exception instanceof ReportingException
                ? $exception->errorCode()
                : 'REPORT_RUN_FAILED';
            $locked->status = ReportRunStatus::FAILED;
            $locked->failed_at = now('UTC');
            $locked->error_code = $errorCode;
            $results->purge($locked->id);
            $locked->save();
            $audit->outbox(
                ReportEventName::RUN_FAILED,
                $locked->id,
                $registry->get($locked->report_code),
                $locked->requester,
                ReportScope::fromArray($locked->scope_snapshot),
                $locked->correlation_id,
                ['filters_hash' => $locked->filters_hash, 'error_code' => $errorCode],
            );
        });
    }
}
