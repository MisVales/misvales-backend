<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\RegistroOperacional;
use App\Models\User;
use App\Services\Audit\DatabaseIncidentRecorder;
use App\Services\Observabilidad\SanitizadorDatos;
use App\Services\Operaciones\ServicioCorteManual;
use App\Services\Operaciones\ServicioFinPeriodoPagoManual;
use App\Services\Reportes\ServicioInicioReportes;
use App\Services\Reportes\ServicioReportes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class CentroOperacionController extends Controller
{
    public function notifications(Request $request): JsonResponse
    {
        $this->authorizeNotifications($request);
        $query = $request->user()->notifications()->latest();
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }
        if ($request->filled('type')) {
            $query->where('data->event_type', $request->string('type'));
        }

        return response()->json(['data' => $query->paginate(min($request->integer('per_page', 30), 100))]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $this->authorizeNotifications($request);

        return response()->json(['data' => ['count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function markNotification(Request $request, string $notification): JsonResponse
    {
        $this->authorizeNotifications($request);
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();

        return response()->json(['data' => $item->refresh()]);
    }

    public function markAllNotifications(Request $request): JsonResponse
    {
        $this->authorizeNotifications($request);
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['unread_count' => 0]]);
    }

    public function reports(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('reports.view_global') || $request->user()->hasPermissionTo('reports.view_branch'), 403);

        return response()->json(['data' => ServicioReportes::REPORTS]);
    }

    public function reportsHome(Request $request, ServicioInicioReportes $service): JsonResponse
    {
        return response()->json(['data' => $service->obtener($request->user())]);
    }

    public function report(Request $request, string $report, ServicioReportes $service): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'uuid'], 'coordinator_id' => ['nullable', 'uuid'],
            'distributor_id' => ['nullable', 'uuid'], 'status' => ['nullable', 'string', 'max:48'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(['data' => $service->ejecutar($report, $filters, $request->user())]);
    }

    public function auditOptions(Request $request): JsonResponse
    {
        $actor = $request->user();
        $canViewGlobal = $actor->hasPermissionTo('audit.view_global');
        abort_unless($canViewGlobal || $actor->hasPermissionTo('audit.view_branch'), 403);
        if ($canViewGlobal) {
            app(DatabaseIncidentRecorder::class)->importPending();
        }

        $branchScopeKey = 'global';
        if (! $canViewGlobal) {
            $branchIds = $actor->roleScopes()
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereNotNull('branch_id')
                ->pluck('branch_id')
                ->sort()
                ->values()
                ->all();
            $branchScopeKey = 'branches_'.md5(json_encode($branchIds));
        }

        $cacheKey = "audit_filter_options_{$branchScopeKey}";

        $data = Cache::remember($cacheKey, 600, function () use ($request): array {
            $query = $this->authorizedAuditQuery($request);

            $events = (clone $query)
                ->select(['event_name', 'entity_type'])
                ->whereNotNull('event_name')
                ->where('event_name', '<>', '')
                ->distinct()
                ->orderBy('entity_type')
                ->orderBy('event_name')
                ->get()
                ->map(fn (AuditLog $audit): array => [
                    'event_name' => $audit->event_name,
                    'entity_type' => $audit->entity_type,
                ])
                ->values()
                ->all();

            $actorRoles = (clone $query)
                ->whereNotNull('actor_role')
                ->where('actor_role', '<>', '')
                ->distinct()
                ->orderBy('actor_role')
                ->pluck('actor_role')
                ->values()
                ->all();

            $results = (clone $query)
                ->whereNotNull('result')
                ->where('result', '<>', '')
                ->distinct()
                ->orderBy('result')
                ->pluck('result')
                ->values()
                ->all();

            return [
                'events' => $events,
                'actor_roles' => $actorRoles,
                'results' => $results,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function audits(Request $request, SanitizadorDatos $sanitizer): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:128'],
            'event_name' => ['nullable', 'string', 'max:255'],
            'event_names' => ['nullable', 'array', 'max:250'],
            'event_names.*' => ['required', 'string', 'max:255', 'distinct'],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'actor_role' => ['nullable', 'string', 'max:64'],
            'result' => ['nullable', 'string', 'max:32'],
            'request_id' => ['nullable', 'string', 'max:128'],
            'trace_id' => ['nullable', 'string', 'max:128'],
            'correlation_id' => ['nullable', 'string', 'max:128'],
            'branch_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($request->user()->hasPermissionTo('audit.view_global')) {
            app(DatabaseIncidentRecorder::class)->importPending();
        }

        $query = $this->authorizedAuditQuery($request)
            ->with(['actor:id,name,email', 'branch:id,name,code'])
            ->latest();

        if ($request->filled('search')) {
            $rawSearch = trim((string) $request->string('search'));
            $s = '%'.$rawSearch.'%';

            // Precargar IDs de usuarios para evitar subconsultas correlacionadas lentas fila a fila
            $matchingActorIds = User::query()
                ->where('name', 'like', $s)
                ->orWhere('email', 'like', $s)
                ->limit(50)
                ->pluck('id')
                ->all();

            $query->where(function ($q) use ($s, $rawSearch, $matchingActorIds): void {
                $q->where('event_name', 'like', $s)
                    ->orWhere('entity_type', 'like', $s)
                    ->orWhere('reason', 'like', $s)
                    ->orWhere('ip_address', 'like', $s)
                    ->orWhere('request_id', $rawSearch)
                    ->orWhere('trace_id', $rawSearch)
                    ->orWhere('correlation_id', $rawSearch)
                    ->orWhere('entity_id', $rawSearch);

                if (! empty($matchingActorIds)) {
                    $q->orWhereIn('actor_id', $matchingActorIds);
                }
            });
        }

        foreach (['event_name', 'entity_type', 'actor_role', 'result', 'request_id', 'trace_id', 'correlation_id', 'branch_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->string($filter));
            }
        }

        if ($request->filled('event_names')) {
            $query->whereIn('event_name', $request->input('event_names'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date('date_from')->startOfDay());
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<', $request->date('date_to')->addDay()->startOfDay());
        }

        $page = $query->paginate(min($request->integer('per_page', 30), 100));
        $page->getCollection()->transform(function (AuditLog $audit) use ($sanitizer): array {
            $audit->actor?->setAppends([]);
            $row = $audit->toArray();
            $row['previous_value'] = $sanitizer->sanitize($row['previous_value']);
            $row['new_value'] = $sanitizer->sanitize($row['new_value']);
            $row['evidence'] = $sanitizer->sanitize($row['evidence']);

            return $row;
        });

        return response()->json(['data' => $page]);
    }

    public function logs(Request $request, SanitizadorDatos $sanitizer): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:128'],
            'channel' => ['nullable', 'string', 'in:APPLICATION,SECURITY,OPERATION,ERROR,AUDIT'],
            'level' => ['nullable', 'string', 'in:DEBUG,INFO,NOTICE,WARNING,ERROR,CRITICAL,ALERT,EMERGENCY'],
            'event' => ['nullable', 'string', 'max:255'],
            'request_id' => ['nullable', 'string', 'max:128'],
            'correlation_id' => ['nullable', 'string', 'max:128'],
            'trace_id' => ['nullable', 'string', 'max:128'],
            'status_code' => ['nullable', 'integer', 'min:100', 'max:599'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $actor = $request->user();
        $canViewGlobal = $actor->hasPermissionTo('logs.view_global');
        abort_unless($canViewGlobal || $actor->hasPermissionTo('logs.view_branch'), 403);
        $query = RegistroOperacional::query()->latest('occurred_at');
        if (! $canViewGlobal) {
            $query->whereIn('branch_id', $actor->roleScopes()
                ->select('branch_id')
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereNotNull('branch_id'));
        }

        if ($request->filled('search')) {
            $search = '%'.trim((string) $request->string('search')).'%';
            $query->where(function ($builder) use ($search): void {
                $builder->where('event', 'like', $search)
                    ->orWhere('path', 'like', $search)
                    ->orWhere('request_id', 'like', $search)
                    ->orWhere('correlation_id', 'like', $search)
                    ->orWhere('trace_id', 'like', $search);
            });
        }

        foreach (['channel', 'level', 'event', 'request_id', 'correlation_id', 'trace_id', 'status_code'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $filter === 'status_code'
                    ? $request->integer($filter)
                    : (string) $request->string($filter));
            }
        }

        if ($request->filled('date_from')) {
            $query->where('occurred_at', '>=', $request->date('date_from')->startOfDay());
        }
        if ($request->filled('date_to')) {
            $query->where('occurred_at', '<', $request->date('date_to')->addDay()->startOfDay());
        }

        $page = $query->paginate(min($request->integer('per_page', 50), 100));
        $page->getCollection()->transform(function (RegistroOperacional $log) use ($sanitizer): array {
            $row = $log->toArray();
            $row['context'] = $sanitizer->sanitize($row['context']);

            return $row;
        });

        return response()->json(['data' => $page]);
    }

    public function currentCutoffSummary(Request $request, ServicioCorteManual $corteManual): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('reports.view_global'), 403);

        return response()->json(['data' => $corteManual->obtenerResumenCorteActual()]);
    }

    public function forceCutoff(Request $request, ServicioCorteManual $corteManual): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('reports.view_global'), 403);

        $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);

        try {
            return response()->json(['data' => $corteManual->forzarCorte($request->user(), $request->input('motivo'))]);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'RELATION_CONFIGURATION_INCOMPLETE') {
                return response()->json(['message' => 'Falta configuración en el sistema (horarios/días) para poder generar el corte.'], 422);
            }
            if ($e->getMessage() === 'PREVIOUS_CUTOFF_NOT_EXPIRED') {
                return response()->json(['message' => 'Antes de cerrar un nuevo corte, primero vence la fecha límite del periodo actual.'], 409);
            }
            throw $e;
        }
    }

    public function forcePaymentDeadline(Request $request, ServicioFinPeriodoPagoManual $periodoPago): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('reports.view_global'), 403);

        $validated = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);

        try {
            return response()->json(['data' => $periodoPago->forzar($request->user(), $validated['motivo'] ?? null)]);
        } catch (\RuntimeException $error) {
            if ($error->getMessage() === 'FORCED_CUTOFF_NOT_FOUND') {
                return response()->json(['message' => 'Primero debe forzar y completar un corte de relaciones.'], 422);
            }

            throw $error;
        }
    }

    private function authorizeNotifications(Request $request): void
    {
        abort_unless($request->user()->hasPermissionTo('notifications.view_own'), 403);
    }

    private function authorizedAuditQuery(Request $request): Builder
    {
        $actor = $request->user();
        $canViewGlobal = $actor->hasPermissionTo('audit.view_global');
        abort_unless($canViewGlobal || $actor->hasPermissionTo('audit.view_branch'), 403);

        $query = AuditLog::query();
        if (! $canViewGlobal) {
            $query->whereIn('branch_id', $actor->roleScopes()
                ->select('branch_id')
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereNotNull('branch_id'));
        }

        return $query;
    }
}
