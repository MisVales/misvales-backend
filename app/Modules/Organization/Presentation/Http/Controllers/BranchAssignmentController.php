<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UserRoleScope;
use App\Modules\Organization\Application\Assignments\UseCases\ListBranchAssignments;
use App\Modules\Organization\Application\Personnel\Queries\PersonnelListCriteria;
use App\Modules\Organization\Presentation\Http\Requests\ListBranchAssignmentsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class BranchAssignmentController extends Controller
{
    public function index(
        ListBranchAssignmentsRequest $request,
        string $id,
        ListBranchAssignments $useCase,
    ): JsonResponse {
        Gate::authorize('viewAny', UserRoleScope::class);

        $status = $request->validated('status');
        if ($status === null && ! $request->boolean('include_history')) {
            $status = 'ACTIVE';
        }

        return response()->json($useCase->handle(
            branchId: $id,
            actorId: $request->user()->id,
            criteria: new PersonnelListCriteria(
                page: $request->integer('page', 1),
                perPage: $request->integer('per_page', 15),
                roleId: $request->validated('role_id'),
                assignmentStatus: $status,
            ),
        )->toArray());
    }
}
