<?php

namespace App\Http\Resources\Api\V1\Conciliacion;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MovimientoBancarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'import_id' => $this->import_id,
            'row_number' => $this->row_number,
            'bank_folio' => $this->bank_folio,
            'payment_reference' => $this->payment_reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'amount' => $this->amount,
            'concept' => $this->concept,
            'relation_id' => $this->relation_id,
            'relation_reference' => $this->relation?->payment_reference,
            'target_voucher_id' => $this->target_voucher_id,
            'target_voucher_folio' => $this->targetVoucher?->folio,
            'distributor_id' => $this->distributor_id,
            'distributor_number' => $this->distributor?->distributor_number,
            'distributor_name' => $this->relation?->header_snapshot['name'] ?? null,
            'balance_before' => $this->balance_before,
            'applied_amount' => $this->applied_amount,
            'surplus_amount' => $this->surplus_amount,
            'classification' => $this->classification,
            'result' => match ($this->classification) {
                'PARTIAL_PAYMENT' => 'ABONO',
                'SETTLEMENT' => 'LIQUIDACIÓN',
                'SURPLUS' => 'LIQUIDACIÓN + EXCEDENTE',
                'DUPLICATE' => 'DUPLICADO',
                default => 'NO_CONCILIADO',
            },
            'reconciliation_status' => $this->reconciliation_status,
            'duplicate_of_id' => $this->duplicate_of_id,
            'reconciled_by' => $this->reconciled_by,
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'manual_request' => $this->whenLoaded('manualRequest', fn () => $this->manualRequest === null ? null : [
                'id' => $this->manualRequest->id,
                'status' => $this->manualRequest->status,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
