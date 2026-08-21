<?php

namespace App\Http\Resources\Api\V1\Vale;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ValeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'folio' => $this->folio, 'type' => $this->type->value, 'status' => $this->status->value,
            'client' => $this->whenLoaded('cliente', fn (): array => ['id' => $this->cliente->id, 'client_number' => $this->cliente->client_number, 'full_name' => trim($this->cliente->first_name.' '.$this->cliente->first_last_name.' '.$this->cliente->second_last_name)]),
            'distributor' => $this->whenLoaded('distribuidora', fn (): array => ['id' => $this->distribuidora->id, 'distributor_number' => $this->distribuidora->distributor_number, 'full_name' => $this->distribuidora->usuario?->name]),
            'product' => $this->whenLoaded('versionProducto', fn (): array => ['id' => $this->product_id, 'version_id' => $this->product_version_id, 'name' => $this->versionProducto->name]),
            'capital' => $this->capital, 'loan_commission_amount' => $this->loan_commission_amount,
            'interest_total' => $this->interest_total, 'insurance_amount' => $this->insurance_amount,
            'misvales_total' => $this->misvales_total, 'distributor_profit_total' => $this->distributor_profit_total,
            'client_total' => $this->client_total, 'fortnights_count' => $this->fortnights_count,
            'client_payment_per_fortnight' => $this->client_payment_per_fortnight,
            'financial_snapshot' => $this->financial_snapshot,
            'installments' => $this->whenLoaded('parcialidades', fn () => $this->parcialidades->map(fn ($item): array => [
                'number' => $item->number, 'capital' => $item->capital, 'loan_commission' => $item->loan_commission,
                'interest' => $item->interest, 'insurance' => $item->insurance, 'distributor_profit' => $item->distributor_profit,
                'misvales_payment' => $item->misvales_payment, 'client_payment' => $item->client_payment, 'due_at' => $item->due_at?->toIso8601String(), 'status' => $item->due_at ? ($item->due_at->isPast() ? 'OVERDUE' : 'PENDING') : 'PENDING',
            ])),
            'generated_at' => $this->generated_at?->toIso8601String(), 'lock_version' => $this->lock_version,
        ];
    }
}
