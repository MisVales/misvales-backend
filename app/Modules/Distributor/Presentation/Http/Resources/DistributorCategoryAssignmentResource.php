<?php

namespace App\Modules\Distributor\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributorCategoryAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'distributor_id' => $this->distributor_id,
            'category_id' => $this->category_id,
            'category_version_id' => $this->category_version_id,
            'profit_rate' => $this->profit_rate_snapshot,
            'effective_from' => $this->effective_from,
            'effective_to' => $this->effective_to,
            'assigned_by' => $this->assigned_by,
            'assigned_role' => $this->assigned_role,
            'assigned_branch_id' => $this->assigned_branch_id,
            'reason' => $this->reason,
        ];
    }
}
