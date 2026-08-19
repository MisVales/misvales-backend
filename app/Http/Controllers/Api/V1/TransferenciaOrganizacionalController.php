<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Distribuidora;
use App\Models\EventoCambioOrganizacional;
use App\Models\User;
use App\Services\Organizacion\ServicioTransferenciasOrganizacionales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TransferenciaOrganizacionalController extends Controller
{
    public function changeCoordinator(Request $request, Distribuidora $distributor, ServicioTransferenciasOrganizacionales $service): JsonResponse
    {
        $data = $request->validate([
            'destination_coordinator_id' => ['required', 'uuid', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $service->cambiarCoordinador(
            $distributor,
            User::findOrFail($data['destination_coordinator_id']),
            $request->user(),
            $data['reason'],
        )], 201);
    }

    public function history(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('organization_changes.view'), 403);

        return response()->json(['data' => EventoCambioOrganizacional::query()->latest('occurred_at')->paginate(50)]);
    }
}
