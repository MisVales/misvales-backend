<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Vale\BuscarClienteParaValeRequest;
use App\Http\Requests\Api\V1\Vale\PrevisualizarValeRequest;
use App\Http\Resources\Api\V1\Vale\ValeResource;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\ProductVersion;
use App\Models\Vale;
use App\Services\Vale\ServicioGeneracionVale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ValeController extends Controller
{
    public function products(Request $request)
    {
        Gate::authorize('create', Vale::class);
        $productos = ProductVersion::query()->with('product')->where('status', 'PUBLISHED')->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('product', fn ($query) => $query->where('status', 'ACTIVE'))->orderBy('nominal_amount')->get();

        return response()->json(['data' => $productos->map(fn (ProductVersion $version): array => [
            'id' => $version->id, 'product_id' => $version->product_id, 'code' => $version->product->code,
            'name' => $version->name, 'nominal_amount' => $version->nominal_amount,
        ])]);
    }

    public function eligibleClients(BuscarClienteParaValeRequest $request, ServicioGeneracionVale $servicio)
    {
        Gate::authorize('create', Vale::class);

        return response()->json(['data' => $servicio->buscarClientesElegibles(
            $request->user(),
            $request->validated('search'),
        )->map(fn ($cliente): array => [
            'id' => $cliente->id,
            'client_number' => $cliente->client_number,
            'full_name' => trim($cliente->first_name.' '.$cliente->first_last_name.' '.$cliente->second_last_name),
        ])->values()]);
    }

    public function financialContext(Request $request, ServicioGeneracionVale $servicio)
    {
        Gate::authorize('create', Vale::class);

        return response()->json(['data' => $servicio->contextoFinanciero($request->user())]);
    }

    public function preview(PrevisualizarValeRequest $request, ServicioGeneracionVale $servicio)
    {
        return response()->json(['data' => $servicio->previsualizar(
            $request->user(),
            $request->validated('client_id'),
            $request->validated('product_version_id'),
            $request->validated('installment_count'),
        )]);
    }

    public function store(PrevisualizarValeRequest $request, ServicioGeneracionVale $servicio): ValeResource
    {
        return new ValeResource($servicio->generar(
            $request->user(),
            $request->validated('client_id'),
            $request->validated('product_version_id'),
            $request->validated('installment_count'),
        ));
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Vale::class);
        $query = Vale::query()->with(['cliente', 'distribuidora.usuario', 'versionProducto', 'parcialidades']);
        $user = $request->user();
        if (! $user->hasPermissionTo('vouchers.view_global')) {
            if ($user->hasPermissionTo('vouchers.view_own')) {
                $query->where('distributor_id', $user->distribuidora?->id ?? 'invalid');
            } elseif ($user->hasPermissionTo('vouchers.view_assigned')) {
                $query->whereIn('distributor_id', CoordinatorDistributorAssignment::query()->where('coordinator_id', $user->id)->where('status', 'ACTIVE')->whereNull('valid_to')->select('distributor_id'));
            } elseif ($user->hasPermissionTo('vouchers.view_branch')) {
                $query->whereIn('branch_id', $user->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->select('branch_id'));
            } else {
                $query->whereRaw('1 = 0');
            }
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->string('client_id'));
        }

        return ValeResource::collection($query->latest('generated_at')->paginate(min((int) $request->input('per_page', 15), 100)));
    }

    public function show(Vale $vale): ValeResource
    {
        Gate::authorize('view', $vale);

        return new ValeResource($vale->load(['cliente', 'distribuidora.usuario', 'versionProducto', 'versionCategoria', 'parcialidades']));
    }
}
