<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UserRoleScope;
use App\Modules\Organization\Application\Personnel\Queries\PersonnelListCriteria;
use App\Modules\Organization\Application\Personnel\UseCases\ListPersonnel;
use App\Modules\Organization\Presentation\Http\Requests\ListPersonnelRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class PersonnelController extends Controller
{
    public function index(ListPersonnelRequest $request, ListPersonnel $useCase): JsonResponse
    {
        Gate::authorize('viewAny', UserRoleScope::class);

        $criteria = new PersonnelListCriteria(
            page: $request->integer('page', 1),
            perPage: $request->integer('per_page', 15),
            branchId: $request->validated('branch_id'),
            roleId: $request->validated('role_id'),
            userState: $request->validated('user_state'),
            assignmentStatus: $request->validated('assignment_status', 'ACTIVE'),
        );

        return response()->json(
            $useCase->handle($request->user()->id, $criteria)->toArray(),
        );
    }
}
