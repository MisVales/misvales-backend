<?php

namespace App\Http\Resources\Api\V1\Conciliacion;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AclaracionPagoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'distributor_id' => $this->distributor_id,
            'distributor_number' => $this->distributor?->distributor_number,
            'distributor_name' => $this->distributor?->usuario?->name,
            'relation_id' => $this->relation_id,
            'relation_reference' => $this->relation?->payment_reference,
            'relation_balance' => $this->relation?->balance,
            'evidence_media_id' => $this->evidence_media_id,
            'reason' => $this->reason,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
