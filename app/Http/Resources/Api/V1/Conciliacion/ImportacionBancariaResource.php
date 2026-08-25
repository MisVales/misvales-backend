<?php

namespace App\Http\Resources\Api\V1\Conciliacion;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ImportacionBancariaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'status' => $this->status,
            'row_count' => $this->row_count,
            'summary' => $this->summary,
            'error_code' => $this->error,
            'uploaded_by' => $this->uploaded_by,
            'branch_id' => $this->branch_id,
            'process_run_id' => $this->process_run_id,
            'replayed' => (bool) ($this->replayed ?? false),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
