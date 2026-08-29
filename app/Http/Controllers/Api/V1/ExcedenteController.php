<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Excedente\CancelarDevolucionRequest;
use App\Http\Requests\Api\V1\Excedente\CompletarDevolucionRequest;
use App\Http\Requests\Api\V1\Excedente\DecidirDevolucionRequest;
use App\Http\Resources\Api\V1\Excedente\DevolucionExcedenteResource;
use App\Http\Resources\Api\V1\Excedente\ExcedenteResource;
use App\Models\ExcedenteDistribuidora;
use App\Models\SolicitudDevolucionExcedente;
use App\Services\Excedente\ServicioExcedente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ExcedenteController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(
            collect(['surpluses.view_own', 'surpluses.view_branch', 'surpluses.view_global'])
                ->contains(fn (string $permission): bool => $request->user()->hasPermissionTo($permission)),
            403,
        );
        $query = ExcedenteDistribuidora::query()->with($this->surplusRelations())->latest();
        $this->scopeSurpluses($query, $request);

        return ExcedenteResource::collection($query->get());
    }

    public function show(ExcedenteDistribuidora $excedente, Request $request): ExcedenteResource
    {
        Gate::forUser($request->user())->authorize('view', $excedente);

        return new ExcedenteResource($excedente->load($this->surplusRelations()));
    }

    public function credit(ExcedenteDistribuidora $excedente, Request $request, ServicioExcedente $service): ExcedenteResource
    {
        Gate::forUser($request->user())->authorize('chooseCredit', $excedente);

        return new ExcedenteResource($service->elegirCredito($excedente, $request->user()));
    }

    public function refund(ExcedenteDistribuidora $excedente, Request $request, ServicioExcedente $service)
    {
        Gate::forUser($request->user())->authorize('requestRefund', $excedente);

        return (new DevolucionExcedenteResource($service->solicitarDevolucion($excedente, $request->user())))->response()->setStatusCode(201);
    }

    public function refunds(Request $request)
    {
        $user = $request->user();
        abort_unless(
            collect(['surpluses.view_global', 'surpluses.view_branch', 'surpluses.view_own', 'refunds.authorize_global', 'refunds.authorize_branch', 'refunds.execute_branch'])
                ->contains(fn (string $permission): bool => $user->hasPermissionTo($permission)),
            403,
        );

        $query = SolicitudDevolucionExcedente::query()->with($this->refundRelations())->latest();
        if ($user->hasPermissionTo('surpluses.view_global') || $user->hasPermissionTo('refunds.authorize_global')) {
            // Alcance global explícito.
        } elseif ($user->hasPermissionTo('surpluses.view_branch') || $user->hasPermissionTo('refunds.authorize_branch') || $user->hasPermissionTo('refunds.execute_branch')) {
            $branches = $user->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->pluck('branch_id');
            $query->whereIn('branch_id', $branches);
            if ($user->hasPermissionTo('refunds.execute_branch') && ! $user->hasPermissionTo('refunds.authorize_branch')) {
                $query->where('status', 'AUTHORIZED');
            }
        } elseif ($user->hasPermissionTo('surpluses.view_own') && $user->distribuidora) {
            $query->whereHas('surplus', fn ($scope) => $scope->where('distributor_id', $user->distribuidora->id));
        } else {
            $query->whereRaw('1 = 0');
        }

        return DevolucionExcedenteResource::collection($query->get());
    }

    public function decide(SolicitudDevolucionExcedente $solicitud, DecidirDevolucionRequest $request, ServicioExcedente $service): DevolucionExcedenteResource
    {
        Gate::forUser($request->user())->authorize('authorize', $solicitud);
        $data = $request->validated();

        return new DevolucionExcedenteResource($service->decidir($solicitud, $request->user(), $data['decision'], $data['reason']));
    }

    public function cancel(SolicitudDevolucionExcedente $solicitud, CancelarDevolucionRequest $request, ServicioExcedente $service): DevolucionExcedenteResource
    {
        Gate::forUser($request->user())->authorize('cancel', $solicitud);

        return new DevolucionExcedenteResource($service->cancelar($solicitud, $request->user(), $request->validated('reason')));
    }

    public function execute(SolicitudDevolucionExcedente $solicitud, CompletarDevolucionRequest $request, ServicioExcedente $service): DevolucionExcedenteResource
    {
        Gate::forUser($request->user())->authorize('complete', $solicitud);

        return new DevolucionExcedenteResource($service->completar($solicitud, $request->user(), $request->validated()));
    }

    private function scopeSurpluses($query, Request $request): void
    {
        $user = $request->user();
        if ($user->hasPermissionTo('surpluses.view_global')) {
            return;
        }
        if ($user->hasPermissionTo('surpluses.view_branch')) {
            $branches = $user->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->pluck('branch_id');
            $query->whereIn('branch_id', $branches);

            return;
        }
        if ($user->hasPermissionTo('surpluses.view_own') && $user->distribuidora) {
            $query->where('distributor_id', $user->distribuidora->id);

            return;
        }
        $query->whereRaw('1 = 0');
    }

    private function surplusRelations(): array
    {
        return ['distributor.usuario', 'branch', 'originRelation', 'bankMovement', 'applications.relation', 'refundRequests.branch', 'refundRequests.requester', 'refundRequests.decisionMaker', 'refundRequests.executor'];
    }

    private function refundRelations(): array
    {
        return ['surplus.distributor.usuario', 'surplus.distributor.cuentaBancariaVigente', 'surplus.originRelation', 'surplus.bankMovement', 'branch', 'requester', 'decisionMaker', 'executor', 'evidence'];
    }
}
