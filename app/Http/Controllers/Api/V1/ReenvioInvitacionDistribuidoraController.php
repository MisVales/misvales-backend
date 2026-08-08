<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ExcepcionDistribuidora;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Distribuidora\ReenviarInvitacionDistribuidoraRequest;
use App\Models\Distribuidora;
use App\Services\Distribuidora\AuditorDistribuidora;
use App\Services\Distribuidora\ServicioInvitacionDistribuidora;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ReenvioInvitacionDistribuidoraController extends Controller
{
    public function store(
        ReenviarInvitacionDistribuidoraRequest $request,
        Distribuidora $distributor,
        ServicioInvitacionDistribuidora $servicio,
        AuditorDistribuidora $auditor,
    ): JsonResponse {
        Gate::authorize('resendActivation', $distributor);
        try {
            $servicio->reenviar($distributor, $request->user());
        } catch (ExcepcionDistribuidora $excepcion) {
            $auditor->registrar(
                'DISTRIBUTOR_ACTIVATION_INVITATION_RESENT',
                'Distributor',
                $distributor->id,
                $request->user(),
                $distributor->branch_id,
                resultado: 'FAILED',
                motivo: $excepcion->codigo,
            );

            throw $excepcion;
        }

        return response()->json([
            'message' => 'Si la cuenta continúa pendiente, recibirá una nueva invitación de activación.',
        ]);
    }
}
