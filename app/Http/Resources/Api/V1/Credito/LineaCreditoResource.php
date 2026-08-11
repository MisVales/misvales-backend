<?php

namespace App\Http\Resources\Api\V1\Credito;

use App\Models\SolicitudIncrementoLinea;
use App\Services\Credito\CalculadorSaldoCredito;
use App\Services\Credito\EvaluadorReglaCincuenta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LineaCreditoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $calculador = app(CalculadorSaldoCredito::class);
        $evaluador = app(EvaluadorReglaCincuenta::class);

        $saldos = $calculador->calcular($this->total_authorized, $this->used_balance);
        $restriccionVigente = $this->restricciones()
            ->whereIn('status', ['ACTIVE', 'RESERVED'])
            ->first();
            
        $reglaCincuenta = $evaluador->evaluar($restriccionVigente, $saldos['available_balance']);
        
        $ultimoMovimiento = $this->movimientos()
            ->orderBy('sequence', 'desc')
            ->first();

        return [
            'id' => $this->id,
            'distributor' => [
                'id' => $this->distribuidora->id,
                'distributor_number' => $this->distribuidora->distributor_number ?? '',
                'full_name' => trim("{$this->distribuidora->first_name} {$this->distribuidora->last_name}"),
            ],
            'total_authorized' => $saldos['total_authorized'],
            'used_balance' => $saldos['used_balance'],
            'available_balance' => $saldos['available_balance'],
            'restriction' => $restriccionVigente ? $reglaCincuenta : null,
            'last_movement' => $ultimoMovimiento ? [
                'type' => $ultimoMovimiento->type,
                'amount' => (string) $ultimoMovimiento->amount,
                'occurred_at' => $ultimoMovimiento->occurred_at->toIso8601String(),
            ] : null,
            'capabilities' => $this->getCapabilities($request->user()),
            'lock_version' => $this->lock_version,
        ];
    }

    private function getCapabilities($user): array
    {
        // Distribuidora puede solicitar si es dueña de la línea
        $isOwner = $user->hasRole('distributor') && $user->id === $this->distributor_id;
        
        // Coordinador/Gerente pueden revisar (el alcance ya está filtrado por el query)
        $isReviewer = $user->hasRole('coordinator');
        $isDecider = $user->hasRole('branch_manager') || $user->hasRole('general_manager') || $user->hasRole('admin');
        
        // Revisar si hay una solicitud activa
        $hasActiveRequest = SolicitudIncrementoLinea::where('credit_line_id', $this->id)
            ->whereIn('status', ['REQUESTED', 'PREAUTHORIZED'])
            ->exists();

        return [
            'can_request_increase' => $isOwner && !$hasActiveRequest,
            'can_review_increase' => $isReviewer,
            'can_decide_increase' => $isDecider,
            'can_view_movements' => true,
        ];
    }
}
