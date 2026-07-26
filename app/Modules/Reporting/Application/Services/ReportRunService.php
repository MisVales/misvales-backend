<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

use App\Models\User;
use App\Modules\Reporting\Domain\Contracts\ReportResultStoreInterface;
use App\Modules\Reporting\Domain\Enums\ReportEventName;
use App\Modules\Reporting\Domain\Enums\ReportRunStatus;
use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use App\Modules\Reporting\Domain\ValueObjects\ReportDefinition;
use App\Modules\Reporting\Domain\ValueObjects\ReportScope;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRun;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRunResult;
use App\Modules\Reporting\Infrastructure\Queue\ExecuteReportRunJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ReportRunService
{
    public function __construct(
        private ReportAuditRecorder $audit,
        private ReportResultStoreInterface $results,
    ) {}

    /** @param array<string, mixed> $filters */
    public function create(
        User $actor,
        ReportDefinition $definition,
        ReportScope $scope,
        array $filters,
        string $filtersHash,
        string $idempotencyKey,
        string $correlationId,
    ): ReportRun {
        $created = false;
        $run = DB::transaction(function () use (
            $actor,
            $definition,
            $scope,
            $filters,
            $filtersHash,
            $idempotencyKey,
            $correlationId,
            &$created,
        ): ReportRun {
            $run = ReportRun::query()->firstOrCreate(
                ['requested_by' => $actor->id, 'idempotency_key' => $idempotencyKey],
                [
                    'id' => (string) Str::uuid(),
                    'run_number' => 'RPT-'.now('UTC')->format('Ymd').'-'.Str::ulid(),
                    'report_code' => $definition->code,
                    'contract_version' => $definition->contractVersion,
                    'status' => ReportRunStatus::QUEUED,
                    'requested_role' => $actor->role_code,
                    'scope_type' => $scope->type,
                    'scope_snapshot' => $scope->toArray(),
                    'filters_json' => $filters,
                    'filters_hash' => $filtersHash,
                    'queued_at' => now('UTC'),
                    'correlation_id' => $correlationId,
                ],
            );
            $created = $run->wasRecentlyCreated;
            if (! $created && ($run->filters_hash !== $filtersHash || $run->report_code !== $definition->code)) {
                throw ReportingException::idempotencyConflict();
            }
            if ($created) {
                $this->audit->outbox(
                    ReportEventName::RUN_REQUESTED,
                    $run->id,
                    $definition,
                    $actor,
                    $scope,
                    $correlationId,
                    ['filters_hash' => $filtersHash, 'run_number' => $run->run_number],
                );
            }

            return $run;
        });

        if ($created) {
            ExecuteReportRunJob::dispatch($run->id)->afterCommit();
        }

        return $run;
    }

    /** @return LengthAwarePaginator<int, ReportRun> */
    public function listOwn(User $actor, int $perPage): LengthAwarePaginator
    {
        return ReportRun::query()
            ->where('requested_by', $actor->id)
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findOwn(User $actor, string $id): ReportRun
    {
        return ReportRun::query()
            ->whereKey($id)
            ->where('requested_by', $actor->id)
            ->first() ?? throw ReportingException::runNotFound();
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function result(User $actor, string $id, int $page, int $perPage): array
    {
        $run = $this->findOwn($actor, $id);
        match ($run->status) {
            ReportRunStatus::COMPLETED => null,
            ReportRunStatus::FAILED => throw ReportingException::runFailed(),
            ReportRunStatus::EXPIRED => throw ReportingException::runExpired(),
            default => throw ReportingException::runNotReady(),
        };

        $total = (int) $run->row_count;
        $blockSize = (int) config('reporting.maximum_page_size', 100);
        $offset = ($page - 1) * $perPage;
        $startBlock = intdiv($offset, $blockSize);
        $endBlock = intdiv(max($offset, $offset + $perPage - 1), $blockSize);
        $blocks = $run->results()
            ->whereBetween('block_number', [$startBlock, $endBlock])
            ->orderBy('block_number')
            ->get();
        $rows = [];
        foreach ($blocks as $block) {
            /** @var array<string, mixed> $payload */
            $payload = $block->payload_protected;
            $blockRows = $payload['rows'] ?? [];
            if (is_array($blockRows)) {
                foreach ($blockRows as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }
        }
        $summaryBlock = ReportRunResult::query()
            ->where('report_run_id', $run->id)
            ->where('block_number', 0)
            ->first();
        /** @var array<string, mixed> $summaryPayload */
        $summaryPayload = $summaryBlock->payload_protected;
        $summary = is_array($summaryPayload['summary'] ?? null) ? $summaryPayload['summary'] : [];
        $offsetInsideBlocks = $offset - ($startBlock * $blockSize);
        $data = array_slice($rows, $offsetInsideBlocks, $perPage);

        return [
            'data' => $data,
            'meta' => [
                'run_id' => $run->id,
                'run_number' => $run->run_number,
                'report_code' => $run->report_code->value,
                'contract_version' => $run->contract_version,
                'timezone' => config('reporting.business_timezone'),
                'as_of' => $run->as_of?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'filters' => $run->filters_json,
                'scope' => $run->scope_snapshot,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
                'summary' => $summary,
            ],
        ];
    }

    public function expire(ReportRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = ReportRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! $locked->status->canTransitionTo(ReportRunStatus::EXPIRED)) {
                throw ReportingException::invalidRunState();
            }
            $this->results->purge($locked->id);
            $locked->status = ReportRunStatus::EXPIRED;
            $locked->result_location = null;
            $locked->save();
            $definition = app(ReportRegistry::class)->get($locked->report_code);
            $this->audit->outbox(
                ReportEventName::RUN_EXPIRED,
                $locked->id,
                $definition,
                $locked->requester,
                ReportScope::fromArray($locked->scope_snapshot),
                $locked->correlation_id,
                ['row_count' => $locked->row_count],
            );
        });
    }
}
