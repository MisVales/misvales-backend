<?php

namespace App\Modules\Distributor\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributorAdminDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'distributor_number' => $this->distributor_number,
            'profile_status' => $this->status,
            'branch' => [
                'id' => $this->branch_id,
            ],
            'lock_version' => $this->lock_version,
            'activated_at' => $this->activated_at,
        ];
    }
}
