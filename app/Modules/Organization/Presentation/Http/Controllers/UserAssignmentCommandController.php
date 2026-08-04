<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UserRoleScope;
use App\Modules\Organization\Application\Assignments\Repositories\AssignmentReadRepository;
use App\Modules\Organization\Application\Assignments\UseCases\AssignPersonnel;
use App\Modules\Organization\Application\Assignments\UseCases\EndAssignment;
use App\Modules\Organization\Application\Assignments\UseCases\UpdateAssignmentDetails;
use App\Modules\Organization\Presentation\Http\Requests\EndAssignmentRequest;
use App\Modules\Organization\Presentation\Http\Requests\StoreAssignmentRequest;
use App\Modules\Organization\Presentation\Http\Requests\UpdateAssignmentRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class UserAssignmentCommandController extends Controller
{
    public function store(
        StoreAssignmentRequest $request,
        string $id,
        AssignPersonnel $useCase,
        AssignmentReadRepository $assignmentReads,
    ): JsonResponse {
        Gate::authorize('create', UserRoleScope::class);

        $assignment = $useCase->handle(
            assignmentId: Str::uuid()->toString(),
            targetUserId: $id,
            roleId: $request->validated('role_id'),
            branchId: $request->validated('branch_id'),
            scope: $request->validated('scope'),
            assignedAt: $request->has('assigned_at')
                ? CarbonImmutable::parse($request->validated('assigned_at'))
                : CarbonImmutable::now(),
            actorId: $request->user()->id,
            assignmentReason: $request->validated('assignment_reason'),
        );

        $assignmentView = collect($assignmentReads->forUser($id, true))
            ->firstWhere('id', $assignment->id()->value());

        return response()->json([
            'message' => 'Asignación creada exitosamente.',
            'assignment' => $assignmentView,
        ], 201);
    }

    public function destroy(
        EndAssignmentRequest $request,
        string $id,
        string $assignmentId,
        EndAssignment $useCase,
    ): JsonResponse {
        Gate::authorize('endAny', UserRoleScope::class);

        $useCase->handle(
            assignmentId: $assignmentId,
            targetUserId: $id,
            actorId: $request->user()->id,
            reason: $request->input('reason', 'REVOKED_BY_ADMIN'),
        );

        return response()->json(['message' => 'Asignación revocada exitosamente.']);
    }

    public function update(
        UpdateAssignmentRequest $request,
        string $id,
        string $assignmentId,
        UpdateAssignmentDetails $useCase,
        AssignmentReadRepository $assignmentReads,
    ): JsonResponse {
        Gate::authorize('updateAny', UserRoleScope::class);

        $assignment = $useCase->handle(
            assignmentId: $assignmentId,
            targetUserId: $id,
            actorId: $request->user()->id,
            assignedAt: $request->has('assigned_at')
                ? CarbonImmutable::parse($request->validated('assigned_at'))
                : null,
            assignmentReason: $request->validated('assignment_reason'),
        );

        $assignmentView = collect($assignmentReads->forUser($id, true))
            ->firstWhere('id', $assignment->id()->value());

        return response()->json([
            'message' => 'Asignación actualizada exitosamente.',
            'assignment' => $assignmentView,
        ]);
    }
}
