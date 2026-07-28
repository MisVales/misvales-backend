<?php

namespace App\Modules\Audit\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Persistence\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Controlador de presentación para la API REST del módulo Audit.
 * Maneja la lectura y listado paginado de eventos de auditoría inmutables.
 */
class AuditController extends Controller
{
    /**
     * Lista de forma paginada los eventos de auditoría utilizando los filtros permitidos.
     * Evalúa las políticas de seguridad antes de devolver información operativa.
     *
     * @tags Audit
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', AuditEvent::class);

        $query = AuditEvent::query();

        // 11.1 Filtros permitidos obligatorios
        $allowedFilters = [
            'event_code', 'category', 'result', 'branch_id', 'requester_user_id',
            'authorizer_user_id', 'executor_user_id', 'subject_type', 'subject_id',
            'subject_public_number', 'process_code', 'request_id', 'trace_id', 'correlation_id',
        ];

        foreach ($allowedFilters as $filter) {
            if ($request->has($filter)) {
                $query->where($filter, $request->get($filter));
            }
        }

        if ($request->has('date_from')) {
            $query->where('occurred_at', '>=', $request->get('date_from'));
        }
        if ($request->has('date_to')) {
            $query->where('occurred_at', '<=', $request->get('date_to'));
        }

        // Orden inmutable
        $events = $query->orderBy('occurred_at', 'desc')->orderBy('id', 'desc')->paginate(20);

        // Resource anónimo para la lista minimizada
        return JsonResource::collection($events->map(function ($event) {
            return [
                'id' => $event->id,
                'event_code' => $event->event_code,
                'occurred_at' => $event->occurred_at,
                'requester_user_id' => $event->requester_user_id,
                'branch_id' => $event->branch_id,
                'subject_type' => $event->subject_type,
                'action' => $event->action,
                'result' => $event->result,
                'subject_public_number' => $event->subject_public_number,
                'has_evidence' => ! empty($event->evidence_file_ids),
            ];
        }));
    }

    /**
     * Devuelve el detalle completo de un evento auditable individual.
     * Solo se exponen los payloads cuando se aprueba la Policy correspondiente.
     *
     * @tags Audit
     *
     * @param  AuditEvent  $auditEvent  El modelo del evento auditable resuelto.
     * @return JsonResponse
     */
    public function show(Request $request, AuditEvent $auditEvent)
    {
        $this->authorize('view', $auditEvent);

        // 11.4 Detalle completo protegido
        return response()->json([
            'data' => $auditEvent->toArray(), // En la vida real, se filtra datos extremadamente sensibles.
        ]);
    }
}
