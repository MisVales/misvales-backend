<?php

namespace App\Http\Controllers\Api\V1\Credito;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Credito\LineaCreditoResource;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\LineaCredito;
use App\Models\UserRoleScope;
use Illuminate\Http\Request;

class LineaCreditoConsultaController extends Controller
{
    public function show(Request $request, string $distributorId)
    {
        $user = $request->user();

        $query = LineaCredito::with([
            'distribuidora',
            'restricciones' => function ($q) {
                $q->whereIn('status', ['ACTIVE', 'RESERVED']);
            },
            'movimientos' => function ($q) {
                $q->orderBy('sequence', 'desc')->take(1);
            }
        ])->where('distributor_id', $distributorId);

        // Aplicar el alcance antes de buscar el registro
        if ($user->hasPermissionTo('credit_lines.view_own')) {
            $distribuidora = \App\Models\Distribuidora::where('user_id', $user->id)->first();
            if ($distribuidora) {
                $query->where('distributor_id', $distribuidora->id);
            } else {
                $query->where('distributor_id', $user->id); // fallback
            }
        } elseif ($user->hasPermissionTo('credit_lines.view_assigned')) {
            $hasAssignment = CoordinatorDistributorAssignment::where('coordinator_id', $user->id)
                ->where('distributor_id', $distributorId)
                ->where('status', 'ACTIVE')
                ->exists();
                
            if (!$hasAssignment) {
                // Forzar fallo si no tiene asignación
                $query->where('id', 'invalid-uuid'); 
            }
        } elseif ($user->hasPermissionTo('credit_lines.view_branch')) {
            $managerScope = UserRoleScope::where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->where('scope_type', 'BRANCH')
                ->first();

            $distributorScope = UserRoleScope::where('user_id', $distributorId)
                ->where('status', 'ACTIVE')
                ->where('scope_type', 'BRANCH')
                ->first();

            if (!$managerScope || !$distributorScope || $managerScope->branch_id !== $distributorScope->branch_id) {
                // Forzar fallo si no son de la misma sucursal
                $query->where('id', 'invalid-uuid');
            }
        } elseif (!$user->hasPermissionTo('credit_lines.view_global')) {
            // Roles sin acceso a ninguna
            $query->where('id', 'invalid-uuid');
        }

        $linea = $query->firstOrFail();

        app(\App\Services\Credito\AuditorIncrementos::class)->registrar(
            'EV-READ-LINE',
            'credit_lines',
            $linea->id,
            null,
            $user,
            $linea->branch_id ?? null,
            [],
            [],
            'Consulta de línea de crédito y saldo vigente.',
            'SUCCESS',
            'v1.0.0'
        );

        return new LineaCreditoResource($linea);
    }
}
