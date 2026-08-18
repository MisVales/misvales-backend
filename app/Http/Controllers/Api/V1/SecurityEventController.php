<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SecurityEventController extends Controller
{
    /**
     * GET /api/v1/security-events
     * Lista los eventos de auditoría y seguridad.
     */
    public function index(Request $request)
    {
        $query = SecurityEvent::query();

        if (! Gate::allows('viewAudit', SecurityEvent::class)) {
            // Un usuario normal solo puede ver sus propios eventos
            $query->where('user_id', $request->user()->id);
        } else {
            // Administrador puede filtrar por user_id específico
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }
        }

        if ($request->has('actor_user_id')) {
            $query->where('actor_user_id', $request->actor_user_id);
        }

        if ($request->has('event_type')) {
            $query->where('event_type', $request->event_type);
        }

        if ($request->has('severity')) {
            $query->where('severity', $request->severity);
        }

        if ($request->has('date_from')) {
            $query->where('occurred_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('occurred_at', '<=', $request->date_to);
        }

        $query->orderBy('occurred_at', 'desc');

        $events = $query->paginate(20);

        return response()->json($events);
    }
}
