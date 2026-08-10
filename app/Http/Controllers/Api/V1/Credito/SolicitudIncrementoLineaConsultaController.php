<?php

namespace App\Http\Controllers\Api\V1\Credito;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Credito\ListarSolicitudesIncrementoRequest;
use App\Http\Resources\Api\V1\Credito\SolicitudIncrementoDetalleResource;
use App\Models\SolicitudIncrementoLinea;
use App\Models\UserRoleScope;
use Illuminate\Support\Facades\Gate;

class SolicitudIncrementoLineaConsultaController extends Controller
{
    public function index(ListarSolicitudesIncrementoRequest $request)
    {
        Gate::authorize('viewAny', SolicitudIncrementoLinea::class);

        $query = SolicitudIncrementoLinea::query()->with([
            'distributor', 
            'distributor.usuario',
            'branch', 
            'coordinator'
        ]);

        $user = $request->user();

        // 1. Aplicar el alcance (Scope) antes de los filtros
        if (!$user->hasPermissionTo('credit_increase_requests.view_global')) {
            if ($user->hasPermissionTo('credit_increase_requests.view_own')) {
                $distribuidorasIds = \App\Models\Distribuidora::where('user_id', $user->id)->pluck('id');
                $query->whereIn('distributor_id', $distribuidorasIds);
            } elseif ($user->hasPermissionTo('credit_increase_requests.view_assigned')) {
                $query->where('coordinator_id', $user->id);
            } elseif ($user->hasPermissionTo('credit_increase_requests.view_branch')) {
                $sucursales = UserRoleScope::where('user_id', $user->id)
                    ->where('status', 'ACTIVE')
                    ->where('scope_type', 'BRANCH')
                    ->pluck('branch_id');
                $query->whereIn('branch_id', $sucursales);
            } else {
                // Si no tiene permiso, no ve nada
                $query->where('id', 'invalid-uuid');
            }
        }

        // 2. Filtros
        if ($request->filled('request_number')) {
            $query->where('request_number', 'ilike', '%' . $request->input('request_number') . '%');
        }
        if ($request->filled('distributor_id')) {
            $query->where('distributor_id', $request->input('distributor_id'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }
        if ($request->filled('coordinator_id')) {
            $query->where('coordinator_id', $request->input('coordinator_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('requested_from')) {
            $query->where('requested_at', '>=', $request->input('requested_from'));
        }
        if ($request->filled('requested_to')) {
            $query->where('requested_at', '<=', $request->input('requested_to'));
        }
        if ($request->filled('manager_decided_from')) {
            $query->where('manager_decided_at', '>=', $request->input('manager_decided_from'));
        }
        if ($request->filled('manager_decided_to')) {
            $query->where('manager_decided_at', '<=', $request->input('manager_decided_to'));
        }

        // 3. Ordenamiento
        if ($request->filled('sort')) {
            $sort = $request->input('sort');
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $column = ltrim($sort, '-');
            $query->orderBy($column, $direction);
        } else {
            $query->latest('requested_at');
        }

        $perPage = $request->input('per_page', 15);
        $solicitudes = $query->paginate($perPage);

        return SolicitudIncrementoDetalleResource::collection($solicitudes);
    }

    public function show(\App\Models\SolicitudIncrementoLinea $solicitud)
    {
        \Illuminate\Support\Facades\Gate::authorize('view', $solicitud);

        $solicitud->load([
            'distribuidora.usuario',
            'sucursal',
            'coordinadorSnapshot',
            'lineaCredito',
            'restriccion',
            'transiciones'
        ]);

        return new SolicitudIncrementoDetalleResource($solicitud);
    }
}
