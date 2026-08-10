<?php

namespace App\Http\Resources\Api\V1\Credito;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LineaCreditoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'] ?? $this->id,
            'distributor_id' => $this->resource['distributor_id'] ?? $this->distributor_id,
            'balances' => [
                'authorized_amount' => (string) ($this->resource['saldos']['monto_autorizado'] ?? $this->authorized_amount),
                'used_balance' => (string) ($this->resource['saldos']['saldo_utilizado'] ?? $this->used_balance),
                'restricted_amount' => (string) ($this->resource['saldos']['monto_restringido'] ?? '0.00'),
                'available_balance' => (string) ($this->resource['saldos']['saldo_disponible'] ?? '0.00'),
            ],
            'active_restrictions' => RestriccionUsoCreditoResource::collection($this->when(isset($this->resource['restricciones_activas']), $this->resource['restricciones_activas'] ?? collect())),
        ];
    }
}
