<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Application\Branches\Queries\BranchListCriteria;
use App\Modules\Organization\Application\Branches\UseCases\ActivateBranch;
use App\Modules\Organization\Application\Branches\UseCases\CreateBranch;
use App\Modules\Organization\Application\Branches\UseCases\DeactivateBranch;
use App\Modules\Organization\Application\Branches\UseCases\GetBranch;
use App\Modules\Organization\Application\Branches\UseCases\ListBranches;
use App\Modules\Organization\Application\Branches\UseCases\UpdateBranch;
use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use App\Modules\Organization\Presentation\Http\Requests\ChangeBranchStatusCompatibilityRequest;
use App\Modules\Organization\Presentation\Http\Requests\ChangeBranchStatusRequest;
use App\Modules\Organization\Presentation\Http\Requests\ListBranchesRequest;
use App\Modules\Organization\Presentation\Http\Requests\StoreBranchRequest;
use App\Modules\Organization\Presentation\Http\Requests\UpdateBranchRequest;
use App\Modules\Organization\Presentation\Http\Resources\BranchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class BranchController extends Controller
{
    public function index(ListBranchesRequest $request, ListBranches $useCase): JsonResponse
    {
        Gate::authorize('viewAny', BranchRecord::class);

        $criteria = new BranchListCriteria(
            page: $request->integer('page', 1),
            perPage: $request->integer('per_page', 15),
            status: $request->validated('status'),
            search: $request->validated('search'),
            sort: $request->validated('sort', 'created_at'),
            direction: $request->validated('direction', 'desc'),
        );

        return response()->json(
            $useCase->handle($request->user()->id, $criteria)->toArray(),
        );
    }

    public function store(StoreBranchRequest $request, CreateBranch $useCase): JsonResponse
    {
        Gate::authorize('create', BranchRecord::class);

        $branch = $useCase->handle(
            id: Str::uuid()->toString(),
            name: $request->validated('name'),
            address: $request->validated('address'),
            actorId: $request->user()->id,
        );

        return response()->json(['data' => $this->resource($branch)], 201);
    }

    public function show(Request $request, string $id, GetBranch $useCase): JsonResponse
    {
        Gate::authorize('viewAny', BranchRecord::class);

        $branch = $useCase->handle($id, $request->user()->id);

        return response()->json(['data' => $this->resource($branch)]);
    }

    public function update(UpdateBranchRequest $request, string $id, UpdateBranch $useCase): JsonResponse
    {
        Gate::authorize('updateAny', BranchRecord::class);

        $branch = $useCase->handle(
            branchId: $id,
            name: $request->validated('name'),
            address: $request->validated('address'),
            expectedVersion: $request->integer('lock_version'),
            actorId: $request->user()->id,
        );

        return response()->json(['data' => $this->resource($branch)]);
    }

    public function activate(ChangeBranchStatusRequest $request, string $id, ActivateBranch $useCase): JsonResponse
    {
        Gate::authorize('activateAny', BranchRecord::class);

        $branch = $useCase->handle(
            branchId: $id,
            expectedVersion: $request->integer('lock_version'),
            actorId: $request->user()->id,
        );

        return response()->json(['data' => $this->resource($branch)]);
    }

    public function deactivate(ChangeBranchStatusRequest $request, string $id, DeactivateBranch $useCase): JsonResponse
    {
        Gate::authorize('deactivateAny', BranchRecord::class);

        $branch = $useCase->handle(
            branchId: $id,
            expectedVersion: $request->integer('lock_version'),
            actorId: $request->user()->id,
        );

        return response()->json(['data' => $this->resource($branch)]);
    }

    public function changeStatus(
        ChangeBranchStatusCompatibilityRequest $request,
        string $id,
        GetBranch $getBranch,
        ActivateBranch $activateBranch,
        DeactivateBranch $deactivateBranch,
    ): JsonResponse {
        $status = $request->validated('status');

        if ($status === 'ACTIVE') {
            Gate::authorize('activateAny', BranchRecord::class);
        } else {
            Gate::authorize('deactivateAny', BranchRecord::class);
        }

        $expectedVersion = $request->validated('lock_version');
        if ($expectedVersion === null) {
            $expectedVersion = $getBranch->handle($id, $request->user()->id)->lockVersion();
        }

        $branch = $status === 'ACTIVE'
            ? $activateBranch->handle($id, $expectedVersion, $request->user()->id)
            : $deactivateBranch->handle($id, $expectedVersion, $request->user()->id);

        return response()->json(['data' => $this->resource($branch)]);
    }

    /** @return array<string, mixed> */
    private function resource(Branch $branch): array
    {
        $record = BranchRecord::query()
            ->withCount(['personnelAssignments as active_personnel_count' => fn ($assignments) => $assignments
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')])
            ->findOrFail($branch->id()->value());

        return [
            ...BranchResource::fromDomain($branch),
            'active_personnel_count' => (int) $record->getAttribute('active_personnel_count'),
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }
}
