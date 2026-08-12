<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\RegistroOperacional;
use App\Services\Observabilidad\SanitizadorDatos;
use App\Services\Reportes\ServicioReportes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['data' => ['unread_count' => 0]]);
    }

    public function reports(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('reports.view_global') || $request->user()->hasPermissionTo('reports.view_branch'), 403);

        return response()->json(['data' => ServicioReportes::REPORTS]);
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

    public function audits(Request $request, SanitizadorDatos $sanitizer): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermissionTo('audit.view_global') || $actor->hasPermissionTo('audit.view_branch'), 403);
        $query = AuditLog::query()->latest();
        if (! $actor->hasPermissionTo('audit.view_global')) {
            $branches = $actor->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->whereNotNull('branch_id')->pluck('branch_id');
            $query->whereIn('branch_id', $branches);
        }
        foreach (['event_name', 'entity_type', 'result', 'request_id', 'trace_id', 'correlation_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter));
            }
        }
        $page = $query->paginate(min($request->integer('per_page', 50), 100));
        $page->getCollection()->transform(function (AuditLog $audit) use ($sanitizer): array {
            $row = $audit->toArray();
            $row['previous_value'] = $sanitizer->sanitize($row['previous_value']);
            $row['new_value'] = $sanitizer->sanitize($row['new_value']);
            $row['evidence'] = $sanitizer->sanitize($row['evidence']);

            return $row;
        });

        return response()->json(['data' => $page]);
    }

    public function logs(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermissionTo('logs.view_global') || $actor->hasPermissionTo('logs.view_branch'), 403);
        $query = RegistroOperacional::query()->latest('occurred_at');
        if (! $actor->hasPermissionTo('logs.view_global')) {
            $branches = $actor->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->whereNotNull('branch_id')->pluck('branch_id');
            $query->whereIn('branch_id', $branches);
        }
        foreach (['channel', 'level', 'request_id', 'correlation_id', 'trace_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter));
            }
        }

        return response()->json(['data' => $query->paginate(min($request->integer('per_page', 50), 100))]);
    }

    private function authorizeNotifications(Request $request): void
    {
        abort_unless($request->user()->hasPermissionTo('notifications.view_own'), 403);
    }
}
