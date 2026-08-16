<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoDistribuidora;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\EventoCambioOrganizacional;
use App\Models\SolicitudTransferenciaCliente;
use App\Models\User;
use App\Services\Organizacion\ServicioTransferenciasOrganizacionales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TransferenciaOrganizacionalController extends Controller
{
    public function destinations(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless(
            $actor->hasPermissionTo('client_transfers.initiate_own')
            || $actor->hasPermissionTo('organization_changes.manage_branch')
            || $actor->hasPermissionTo('organization_changes.manage_global'),
            403,
        );
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $ownDistributorId = $actor->distribuidora()->value('id');
        $query = Distribuidora::query()
            ->select(['id', 'user_id', 'branch_id', 'distributor_number', 'status'])
            ->with(['usuario:id,name', 'sucursal:id,name'])
            ->where('status', EstadoDistribuidora::ACTIVA)
            ->when($ownDistributorId, fn (Builder $builder, string $id) => $builder->whereKeyNot($id))
            ->when($filters['search'] ?? null, function (Builder $builder, string $search): void {
                $term = trim($search);
                $builder->where(function (Builder $candidate) use ($term): void {
                    $candidate->where('distributor_number', 'ilike', "%{$term}%")
                        ->orWhereHas('usuario', fn (Builder $user) => $user->where('name', 'ilike', "%{$term}%"));
                });
            });

        if (! $actor->hasPermissionTo('organization_changes.manage_global')
            && $actor->hasPermissionTo('organization_changes.manage_branch')) {
            $branches = $actor->roleScopes()
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereNotNull('branch_id')
                ->pluck('branch_id');
            $query->whereIn('branch_id', $branches);
        }

        $destinations = $query->orderBy('distributor_number')->limit(100)->get()->map(fn (Distribuidora $distributor): array => [
            'id' => $distributor->id,
            'distributor_number' => $distributor->distributor_number,
            'full_name' => $distributor->usuario?->name,
            'branch' => $distributor->sucursal ? [
                'id' => $distributor->sucursal->id,
                'name' => $distributor->sucursal->name,
            ] : null,
        ]);

        return response()->json(['data' => $destinations]);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermissionTo('client_transfers.view'), 403);
        $query = SolicitudTransferenciaCliente::query()->latest();
        $distribuidora = $actor->distribuidora()->value('id');
        if ($distribuidora) {
            $query->where(fn (Builder $q) => $q->where('origin_distributor_id', $distribuidora)->orWhere('destination_distributor_id', $distribuidora));
        } elseif ($actor->hasRole('coordinator')) {
            $ids = CoordinatorDistributorAssignment::query()->where('coordinator_id', $actor->id)->where('status', 'ACTIVE')->whereNull('valid_to')->pluck('distributor_id');
            $query->whereIn('origin_distributor_id', $ids);
        } elseif (! $actor->hasPermissionTo('organization_changes.manage_global')) {
            $branches = $actor->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->whereNotNull('branch_id')->pluck('branch_id');
            $query->where(fn (Builder $q) => $q->whereIn('origin_branch_id', $branches)->orWhereIn('destination_branch_id', $branches));
        }

        return response()->json(['data' => $query->paginate(50)]);
    }

    public function initiate(Request $request, Cliente $client, ServicioTransferenciasOrganizacionales $service): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('client_transfers.initiate_own'), 403);
        $data = $request->validate(['destination_distributor_id' => ['required', 'uuid', 'exists:distributors,id']]);

        return response()->json(['data' => $service->iniciar($client, Distribuidora::findOrFail($data['destination_distributor_id']), $request->user())], 201);
    }

    public function preaccept(Request $request, SolicitudTransferenciaCliente $transfer, ServicioTransferenciasOrganizacionales $service): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('client_transfers.receive_own'), 403);
        $data = $request->validate(['accept' => ['required', 'boolean']]);

        return response()->json(['data' => $service->preaceptar($transfer, $request->user(), $data['accept'])]);
    }

    public function originDecision(Request $request, SolicitudTransferenciaCliente $transfer, ServicioTransferenciasOrganizacionales $service): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('client_transfers.decide_assigned'), 403);
        $data = $request->validate(['authorize' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $service->decidirSalida($transfer, $request->user(), $data['authorize'], $data['reason'])]);
    }

    public function complete(Request $request, SolicitudTransferenciaCliente $transfer, ServicioTransferenciasOrganizacionales $service): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('client_transfers.receive_own'), 403);

        return response()->json(['data' => $service->completar($transfer, $request->user())]);
    }

    public function cancel(Request $request, SolicitudTransferenciaCliente $transfer, ServicioTransferenciasOrganizacionales $service): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('client_transfers.initiate_own'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $service->cancelar($transfer, $request->user(), $data['reason'])]);
    }

    public function reassignClient(Request $request, Cliente $client, ServicioTransferenciasOrganizacionales $service): JsonResponse
    {
        $data = $request->validate(['destination_distributor_id' => ['required', 'uuid', 'exists:distributors,id'], 'reason' => ['required', 'string', 'max:1000']]);
        $assignment = $service->reasignarCliente($client, Distribuidora::findOrFail($data['destination_distributor_id']), $request->user(), $data['reason']);

        return response()->json(['data' => $assignment], 201);
    }

    public function changeBranch(Request $request, Distribuidora $distributor, ServicioTransferenciasOrganizacionales $service): JsonResponse
    {
        $data = $request->validate(['destination_branch_id' => ['required', 'uuid', 'exists:branches,id'], 'destination_coordinator_id' => ['required', 'uuid', 'exists:users,id'], 'reason' => ['required', 'string', 'max:1000']]);
        $result = $service->cambiarSucursal($distributor, $data['destination_branch_id'], User::findOrFail($data['destination_coordinator_id']), $request->user(), $data['reason']);

        return response()->json(['data' => $result]);
    }

    public function changeCoordinator(Request $request, Distribuidora $distributor, ServicioTransferenciasOrganizacionales $service): JsonResponse
    {
        $data = $request->validate(['destination_coordinator_id' => ['required', 'uuid', 'exists:users,id'], 'reason' => ['required', 'string', 'max:1000']]);
        $assignment = $service->cambiarCoordinador($distributor, User::findOrFail($data['destination_coordinator_id']), $request->user(), $data['reason']);

        return response()->json(['data' => $assignment], 201);
    }

    public function coordinatorExit(Request $request, User $coordinator, ServicioTransferenciasOrganizacionales $service): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.distributor_id' => ['required', 'uuid', 'distinct', 'exists:distributors,id'],
            'assignments.*.destination_coordinator_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        return response()->json(['data' => $service->reasignarSalidaCoordinador($coordinator, $data['assignments'], $request->user(), $data['reason'])], 201);
    }

    public function history(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('organization_changes.view'), 403);

        return response()->json(['data' => EventoCambioOrganizacional::query()->latest('occurred_at')->paginate(50)]);
    }
}
