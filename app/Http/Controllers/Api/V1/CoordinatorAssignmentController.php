<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoDistribuidora;
use App\Http\Controllers\Controller;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\UserRoleScope;
use App\Services\Audit\SecurityAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CoordinatorAssignmentController extends Controller
{
    public function distributors(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CoordinatorDistributorAssignment::class);

        $validated = $request->validate([
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
        ]);
        $branchId = $validated['branch_id'];

        if (! $request->user()->hasScopeForBranch($branchId)) {
            return response()->json([
                'code' => 'ORGANIZATION_SCOPE_DENIED',
                'message' => 'La sucursal no está dentro del alcance organizacional autorizado.',
            ], 403);
        }

        $distributors = Distribuidora::query()
            ->with(['solicitud.datosPersonales', 'coordinadorVigente.coordinator:id,name'])
            ->where('branch_id', $branchId)
            ->where('status', EstadoDistribuidora::ACTIVA->value)
            ->orderBy('distributor_number')
            ->get()
            ->map(function (Distribuidora $distributor): array {
                $personalData = $distributor->solicitud?->datosPersonales;

                return [
                    'id' => $distributor->id,
                    'distributor_number' => $distributor->distributor_number,
                    'status' => $distributor->status->value,
                    'branch' => ['id' => $distributor->branch_id],
                    'coordinator' => [
                        'id' => $distributor->coordinadorVigente?->coordinator_id,
                        'name' => $distributor->coordinadorVigente?->coordinator?->name,
                    ],
                    'applicant' => [
                        'full_name' => $personalData === null ? null : trim(implode(' ', array_filter([
                            $personalData->first_name,
                            $personalData->first_last_name,
                            $personalData->second_last_name,
                        ]))),
                    ],
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => $distributors]);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CoordinatorDistributorAssignment::class);

        $validated = $request->validate([
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
        ]);
        $branchId = $validated['branch_id'];

        if (! $request->user()->hasScopeForBranch($branchId)) {
            return response()->json([
                'code' => 'ORGANIZATION_SCOPE_DENIED',
                'message' => 'La sucursal no está dentro del alcance organizacional autorizado.',
            ], 403);
        }

        $assignments = CoordinatorDistributorAssignment::query()
            ->with(['coordinator:id,name,email', 'assignedBy:id,name', 'endedBy:id,name'])
            ->where('branch_id', $branchId)
            ->when(! $request->boolean('include_history'), fn ($query) => $query
                ->where('status', 'ACTIVE')
                ->whereNull('valid_to'))
            ->orderByDesc('valid_from')
            ->get();

        $distributors = Distribuidora::query()
            ->with('solicitud.datosPersonales')
            ->whereIn('id', $assignments->pluck('distributor_id'))
            ->get()
            ->keyBy('id');

        return response()->json(['data' => $assignments
            ->map(fn (CoordinatorDistributorAssignment $assignment): array => $this->serialize(
                $assignment,
                $distributors->get($assignment->distributor_id),
            ))
            ->values()
            ->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'coordinator_id' => ['required', 'uuid', 'exists:users,id'],
            'distributor_id' => ['required', 'uuid', 'exists:distributors,id'],
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'assignment_reason' => ['nullable', 'string', 'max:500'],
        ]);

        Gate::authorize('manage', [CoordinatorDistributorAssignment::class, $validated['branch_id']]);

        $isCoordinator = UserRoleScope::query()
            ->where('user_id', $validated['coordinator_id'])
            ->whereHas('role', fn ($roles) => $roles->where('code', 'coordinator'))
            ->where('branch_id', $validated['branch_id'])
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->exists();

        if (! $isCoordinator) {
            return response()->json([
                'code' => 'COORDINATOR_NOT_ACTIVE_IN_BRANCH',
                'message' => 'El usuario especificado no es un coordinador activo en esta sucursal.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $validated): JsonResponse {
            $distributor = Distribuidora::query()
                ->with('solicitud.datosPersonales')
                ->lockForUpdate()
                ->findOrFail($validated['distributor_id']);

            if ($distributor->branch_id !== $validated['branch_id']) {
                return response()->json([
                    'code' => 'DISTRIBUTOR_BRANCH_MISMATCH',
                    'message' => 'La distribuidora no pertenece a la sucursal indicada.',
                ], 422);
            }

            $activeAssignment = CoordinatorDistributorAssignment::query()
                ->where('distributor_id', $distributor->id)
                ->where('status', 'ACTIVE')
                ->whereNull('valid_to')
                ->lockForUpdate()
                ->first();

            if ($activeAssignment?->coordinator_id === $validated['coordinator_id']) {
                return response()->json([
                    'message' => 'Esta distribuidora ya está asignada a este coordinador.',
                    'data' => $this->serialize($activeAssignment->load('coordinator'), $distributor),
                ]);
            }

            $effectiveAt = now();
            if ($activeAssignment !== null) {
                if ($effectiveAt->lessThan($activeAssignment->valid_from->copy()->addSecond())) {
                    $effectiveAt = $activeAssignment->valid_from->copy()->addSecond();
                }
                $activeAssignment->forceFill([
                    'status' => 'REASSIGNED',
                    'valid_to' => $effectiveAt,
                    'ended_by' => $request->user()->id,
                    'end_reason' => 'Reasignación a otro coordinador',
                    'lock_version' => $activeAssignment->lock_version + 1,
                ])->save();

                app(SecurityAuditService::class)->log($request, [
                    'event_type' => 'COORDINATOR_ASSIGNMENT_ENDED',
                    'severity' => 'INFO',
                    'outcome' => 'SUCCESS',
                    'entity_type' => 'CoordinatorDistributorAssignment',
                    'entity_id' => $activeAssignment->id,
                    'branch_id' => $activeAssignment->branch_id,
                    'metadata' => ['reason' => 'REASSIGNED', 'distributor_id' => $distributor->id],
                ]);
            }

            $newAssignment = CoordinatorDistributorAssignment::query()->create([
                'id' => Str::uuid()->toString(),
                'coordinator_id' => $validated['coordinator_id'],
                'distributor_id' => $distributor->id,
                'branch_id' => $validated['branch_id'],
                'valid_from' => $effectiveAt,
                'status' => 'ACTIVE',
                'assigned_by' => $request->user()->id,
                'assignment_reason' => $validated['assignment_reason'] ?? null,
            ]);

            app(SecurityAuditService::class)->log($request, [
                'event_type' => $activeAssignment === null
                    ? 'COORDINATOR_ASSIGNMENT_CREATED'
                    : 'COORDINATOR_ASSIGNMENT_REASSIGNED',
                'severity' => 'INFO',
                'outcome' => 'SUCCESS',
                'entity_type' => 'CoordinatorDistributorAssignment',
                'entity_id' => $newAssignment->id,
                'user_id' => $validated['coordinator_id'],
                'branch_id' => $validated['branch_id'],
                'metadata' => [
                    'distributor_id' => $distributor->id,
                    'previous_assignment_id' => $activeAssignment?->id,
                ],
            ]);

            return response()->json([
                'message' => $activeAssignment === null
                    ? 'Distribuidora asignada correctamente.'
                    : 'Distribuidora reasignada correctamente.',
                'data' => $this->serialize($newAssignment->load('coordinator'), $distributor),
            ], 201);
        });
    }

    public function destroy(Request $request, CoordinatorDistributorAssignment $assignment): JsonResponse
    {
        Gate::authorize('manage', [CoordinatorDistributorAssignment::class, $assignment->branch_id]);

        $validated = $request->validate([
            'end_reason' => ['required', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $assignment, $validated): JsonResponse {
            $lockedAssignment = CoordinatorDistributorAssignment::query()
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            if ($lockedAssignment->status !== 'ACTIVE' || $lockedAssignment->valid_to !== null) {
                return $this->errorResponse($request, 'ASSIGNMENT_ALREADY_CLOSED', 'La asignación ya está inactiva.', 409);
            }

            $distributor = Distribuidora::query()
                ->lockForUpdate()
                ->find($lockedAssignment->distributor_id);

            if ($distributor?->status === EstadoDistribuidora::ACTIVA) {
                return $this->errorResponse($request, 'ACTIVE_DISTRIBUTOR_REQUIRES_COORDINATOR', 'Una distribuidora activa no puede quedar sin coordinador; debe reasignarse.', 409);
            }

            $closedAt = now();
            if ($closedAt->lessThanOrEqualTo($lockedAssignment->valid_from)) {
                $closedAt = $lockedAssignment->valid_from->copy()->addSecond();
            }

            $lockedAssignment->forceFill([
                'status' => 'ENDED',
                'valid_to' => $closedAt,
                'ended_by' => $request->user()->id,
                'end_reason' => $validated['end_reason'],
                'lock_version' => $lockedAssignment->lock_version + 1,
            ])->save();

            app(SecurityAuditService::class)->log($request, [
                'event_type' => 'COORDINATOR_ASSIGNMENT_ENDED',
                'severity' => 'INFO',
                'outcome' => 'SUCCESS',
                'entity_type' => 'CoordinatorDistributorAssignment',
                'entity_id' => $lockedAssignment->id,
                'branch_id' => $lockedAssignment->branch_id,
                'metadata' => [
                    'distributor_id' => $lockedAssignment->distributor_id,
                    'reason' => $validated['end_reason'],
                ],
            ]);

            return response()->json([
                'message' => 'Asignación terminada sin eliminar el historial.',
                'data' => $this->serialize($lockedAssignment, $distributor),
            ]);
        });
    }

    private function errorResponse(Request $request, string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => [
            'code' => $code,
            'message' => $message,
            'fields' => (object) [],
            'details' => (object) [],
            'request_id' => $request->attributes->get('request_id') ?? $request->header('X-Request-Id'),
        ]], $status);
    }

    /** @return array<string, mixed> */
    private function serialize(
        CoordinatorDistributorAssignment $assignment,
        ?Distribuidora $distributor,
    ): array {
        $personalData = $distributor?->solicitud?->datosPersonales;
        $fullName = $personalData === null ? null : trim(implode(' ', array_filter([
            $personalData->first_name,
            $personalData->first_last_name,
            $personalData->second_last_name,
        ])));

        return [
            ...$assignment->toArray(),
            'distributor' => $distributor === null ? null : [
                'id' => $distributor->id,
                'distributor_number' => $distributor->distributor_number,
                'full_name' => $fullName,
                'status' => $distributor->status->value,
                'branch_id' => $distributor->branch_id,
            ],
        ];
    }
}
