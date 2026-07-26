<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Domain\Contracts\ReportResultStoreInterface;
use App\Modules\Reporting\Domain\ValueObjects\ReportResult;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRunResult;
use Illuminate\Support\Str;

final class DatabaseReportResultStore implements ReportResultStoreInterface
{
    public function storeBlock(string $runId, int $blockNumber, ReportResult $result): void
    {
        $payload = [
            'rows' => $result->rows,
            'summary' => $result->summary,
            'pagination' => $result->pagination,
            'as_of' => $result->asOf->format(DATE_ATOM),
        ];
        ReportRunResult::query()->create([
            'id' => (string) Str::uuid(),
            'report_run_id' => $runId,
            'block_number' => $blockNumber,
            'row_count' => count($result->rows),
            'payload_protected' => $payload,
            'payload_hash' => hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
    }

    public function purge(string $runId): void
    {
        ReportRunResult::query()->where('report_run_id', $runId)->delete();
    }
}
