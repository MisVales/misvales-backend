<?php

namespace App\Http\Resources\Api\V1\Conciliacion;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SolicitudConciliacionManualResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_movement_id' => $this->bank_movement_id,
            'bank_folio' => $this->movement?->bank_folio,
            'amount' => $this->movement?->amount,
            'relation_id' => $this->relation_id,
            'relation_reference' => $this->relation?->payment_reference,
            'distributor_name' => $this->relation?->distribuidora?->usuario?->name,
            'clarification_id' => $this->clarification_id,
            'branch_id' => $this->branch_id,
            'reason' => $this->reason,
            'status' => $this->status,
            'requested_by' => $this->requested_by,
            'requested_by_name' => $this->requester?->name,
            'authorized_by' => $this->authorized_by,
            'authorized_by_name' => $this->authorizer?->name,
            'decision_reason' => $this->decision_reason,
            'executed_by' => $this->executed_by,
            'executed_by_name' => $this->executor?->name,
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
