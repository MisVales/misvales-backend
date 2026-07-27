<?php

namespace App\Modules\Distributor\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributorCapabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'can_access' => $this['can_access'] ?? false,
            'can_issue_vouchers' => $this['can_issue_vouchers'] ?? false,
            'can_request_credit_increase' => $this['can_request_credit_increase'] ?? false,
            'can_view_relations' => $this['can_view_relations'] ?? false,
            'can_submit_clarifications' => $this['can_submit_clarifications'] ?? false,
            'can_request_point_redemption' => $this['can_request_point_redemption'] ?? false,
            'blocking_codes' => $this['blocking_codes'] ?? [],
        ];
    }
}
