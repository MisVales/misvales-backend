<?php

namespace App\Http\Controllers\Api\V1\Credito;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Credito\LineaCreditoResource;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\LineaCredito;
use App\Models\UserRoleScope;
use App\Services\Credito\AuditorIncrementos;
use Illuminate\Http\Request;

class LineaCreditoConsultaController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = LineaCredito::query()->with([
            'distribuidora.usuario',
            'distribuidora.relacionVigente',
            'restricciones' => fn ($query) => $query->whereIn('status', ['ACTIVE', 'RESERVED']),
            'movimientos' => fn ($query) => $query->orderByDesc('sequence')->limit(1),
        ]);

        if ($user->hasPermissionTo('credit_lines.view_own')) {
            $query->whereHas('distribuidora', fn ($query) => $query->where('user_id', $user->id));
        } elseif ($user->hasPermissionTo('credit_lines.view_assigned')) {
            $query->whereIn('distributor_id', CoordinatorDistributorAssignment::query()
                ->where('coordinator_id', $user->id)
                ->where('status', 'ACTIVE')
                ->whereNull('valid_to')
                ->select('distributor_id'));
        } elseif ($user->hasPermissionTo('credit_lines.view_branch')) {
            $branches = UserRoleScope::query()
                ->where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->where('scope_type', 'BRANCH')
                ->pluck('branch_id');
            $query->whereHas('distribuidora', fn ($query) => $query->whereIn('branch_id', $branches));
        } elseif (! $user->hasPermissionTo('credit_lines.view_global')) {
            $query->whereRaw('1 = 0');
        }

        return LineaCreditoResource::collection($query->orderBy('created_at')->get());
    }

    public function show(Request $request, string $distributorId)
    {
        $user = $request->user();

        $query = LineaCredito::with([
            'distribuidora.usuario',
            'distribuidora.relacionVigente',
            'restricciones' => function ($q) {
                $q->whereIn('status', ['ACTIVE', 'RESERVED']);
            },
            'movimientos' => function ($q) {
                $q->orderBy('sequence', 'desc')->take(1);
            },
        ])->where('distributor_id', $distributorId);

        // Aplicar el alcance antes de buscar el registro
        if ($user->hasPermissionTo('credit_lines.view_own')) {
            $distribuidora = Distribuidora::where('user_id', $user->id)->first();
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

            if (! $hasAssignment) {
                // Forzar fallo si no tiene asignación
                $query->where('id', 'invalid-uuid');
            }
        } elseif ($user->hasPermissionTo('credit_lines.view_branch')) {
            $branches = UserRoleScope::query()
                ->where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->where('scope_type', 'BRANCH')
                ->pluck('branch_id');
            $query->whereHas('distribuidora', fn ($query) => $query->whereIn('branch_id', $branches));
        } elseif (! $user->hasPermissionTo('credit_lines.view_global')) {
            // Roles sin acceso a ninguna
            $query->where('id', 'invalid-uuid');
        }

        $linea = $query->firstOrFail();

        app(AuditorIncrementos::class)->registrar(
            'EV-READ-LINE',
            'credit_lines',
            $linea->id,
            null,
            $user,
            $linea->distribuidora?->branch_id,
            [],
            [],
            'Consulta de línea de crédito y saldo vigente.',
            'SUCCESS'
        );

        return new LineaCreditoResource($linea);
    }
}
