<?php

namespace App\Http\Resources\Api\V1\Credito;

use App\Services\Credito\CalculadorSaldoCredito;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoLineaCreditoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $calculador = app(CalculadorSaldoCredito::class);

        // Calcular saldos disponibles al vuelo matemáticamente desde los snapshots
        $saldoAntes = $calculador->calcular($this->total_authorized_before, $this->used_balance_before);
        $saldoDespues = $calculador->calcular($this->total_authorized_after, $this->used_balance_after);

        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'type' => $this->type,
            'amount' => number_format((float) $this->amount, 4, '.', ''),
            'total_authorized_before' => $saldoAntes['total_authorized'],
            'total_authorized_after' => $saldoDespues['total_authorized'],
            'used_balance_before' => $saldoAntes['used_balance'],
            'used_balance_after' => $saldoDespues['used_balance'],
            'available_balance_before' => $saldoAntes['available_balance'],
            'available_balance_after' => $saldoDespues['available_balance'],
            'source' => [
                'type' => $this->source_type,
                'id' => $this->source_id,
            ],
            'performed_by' => $this->realizadoPor ? [
                'id' => $this->realizadoPor->id,
                'name' => trim("{$this->realizadoPor->first_name} {$this->realizadoPor->last_name}"),
            ] : null,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
