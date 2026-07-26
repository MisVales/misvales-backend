<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Resources;

use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportRun */
final class ReportRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_number' => $this->run_number,
            'report_code' => $this->report_code->value,
            'contract_version' => $this->contract_version,
            'status' => $this->status->value,
            'scope' => $this->scope_snapshot,
            'filters' => $this->filters_json,
            'queued_at' => $this->queued_at->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'started_at' => $this->started_at?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'completed_at' => $this->completed_at?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'failed_at' => $this->failed_at?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'expires_at' => $this->expires_at?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'as_of' => $this->as_of?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'row_count' => $this->row_count,
            'error_code' => $this->error_code,
            'correlation_id' => $this->correlation_id,
        ];
    }
}
