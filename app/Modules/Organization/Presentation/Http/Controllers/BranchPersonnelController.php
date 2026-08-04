<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Application\Personnel\Queries\PersonnelListCriteria;
use App\Modules\Organization\Application\Personnel\UseCases\ListBranchPersonnel;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use App\Modules\Organization\Presentation\Http\Requests\ListBranchPersonnelRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class BranchPersonnelController extends Controller
{
    public function index(
        ListBranchPersonnelRequest $request,
        string $id,
        ListBranchPersonnel $useCase,
    ): JsonResponse {
        Gate::authorize('viewAny', BranchRecord::class);

        $criteria = new PersonnelListCriteria(
            page: $request->integer('page', 1),
            perPage: $request->integer('per_page', 15),
            roleId: $request->validated('role_id'),
            userState: $request->validated('state'),
        );

        return response()->json($useCase->handle(
            branchId: $id,
            actorId: $request->user()->id,
            criteria: $criteria,
        )->toArray());
    }
}
