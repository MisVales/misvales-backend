<?php

namespace App\Http\Resources\Api\V1\Credito;

use App\Models\SolicitudIncrementoLinea;
use App\Services\ConfiguracionServicio;
use App\Services\Credito\CalculadorSaldoCredito;
use App\Services\Credito\EvaluadorReglaCincuenta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

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

        $toleranciaVigente = $restriccionVigente
            ? (string) app(ConfiguracionServicio::class)->resolver('CREDIT_TOLERANCE_AMOUNT')['value']
            : null;
        $reglaCincuenta = $evaluador->evaluar($restriccionVigente, $saldos['available_balance'], $toleranciaVigente);

        $ultimoMovimiento = $this->movimientos()
            ->orderBy('sequence', 'desc')
            ->first();

        return [
            'id' => $this->id,
            'distributor' => [
                'id' => $this->distribuidora->id,
                'distributor_number' => $this->distribuidora->distributor_number ?? '',
                'full_name' => $this->distribuidora->usuario?->name ?? '',
            ],
            'total_authorized' => $saldos['total_authorized'],
            'used_balance' => $saldos['used_balance'],
            'available_balance' => $saldos['available_balance'],
            'current_debt' => (string) ($this->distribuidora->relacionVigente?->balance ?? '0.0000'),
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
        $isOwner = $user->hasPermissionTo('credit_increase_requests.create_own')
            && $user->id === $this->distribuidora?->user_id;

        // Coordinador/Gerente pueden revisar (el alcance ya está filtrado por el query)
        $isReviewer = $user->hasPermissionTo('credit_increase_requests.preauthorize_assigned')
            || $user->hasPermissionTo('credit_increase_requests.reject_assigned');
        $isDecider = $user->hasPermissionTo('credit_increase_requests.decide_branch')
            || $user->hasPermissionTo('credit_increase_requests.decide_global');

        // Revisar si hay una solicitud activa
        $hasActiveRequest = SolicitudIncrementoLinea::where('credit_line_id', $this->id)
            ->whereIn('status', ['REQUESTED', 'PREAUTHORIZED'])
            ->exists();

        return [
            'can_request_increase' => $isOwner && ! $hasActiveRequest,
            'can_review_increase' => $isReviewer,
            'can_decide_increase' => $isDecider,
            'can_view_movements' => Gate::allows('viewMovements', $this->resource),
        ];
    }
}
