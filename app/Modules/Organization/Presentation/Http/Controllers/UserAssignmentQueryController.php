<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Application\Assignments\UseCases\ListUserAssignments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class UserAssignmentQueryController extends Controller
{
    public function index(Request $request, string $id, ListUserAssignments $useCase): JsonResponse
    {
        Gate::authorize('viewAny', UserRoleScope::class);
        User::query()->findOrFail($id);

        return response()->json(
            $useCase->handle($id, $request->user()->id, $request->boolean('include_history')),
        );
    }
}
