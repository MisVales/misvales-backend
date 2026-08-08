<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Audit\SecurityAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class BranchController extends Controller
{
    /**
     * List branches.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Branch::class);

        $query = Branch::query();

        // Enforce scope: if the user does NOT have global scope, filter to branches they have access to.
        $user = $request->user();
        
        $hasGlobalScope = $user->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('scope_type', 'GLOBAL')
            ->exists();

        if (!$hasGlobalScope) {
            $branchIds = $user->roleScopes()
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->where('scope_type', 'BRANCH')
                ->pluck('branch_id')
                ->unique();
                
            $query->whereIn('id', $branchIds);
        }

        return response()->json($query->get());
    }

    /**
     * Create a new branch.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Branch::class);

        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:branches,code',
            'name' => 'required|string|max:150',
            // Only Matriz is headquarters, and it is seeded. Others are false.
        ]);

        $branch = Branch::create([
            'id' => Str::uuid(),
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'is_headquarters' => false,
            'status' => 'ACTIVE',
            'created_by' => $request->user()->id,
        ]);

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'BRANCH_CREATED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'Branch',
            'entity_id' => $branch->id,
            'metadata' => ['code' => $branch->code],
        ]);

        return response()->json($branch, 201);
    }

    /**
     * View specific branch.
     */
    public function show(Request $request, Branch $branch)
    {
        Gate::authorize('view', $branch);
        return response()->json($branch);
    }

    /**
     * Update branch general data.
     */
    public function update(Request $request, Branch $branch)
    {
        Gate::authorize('update', $branch);

        $validated = $request->validate([
            'code' => 'sometimes|string|max:20|unique:branches,code,' . $branch->id,
            'name' => 'sometimes|string|max:150',
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $branch->fill($validated);
        $branch->updated_by = $request->user()->id;
        $branch->lock_version++;
        $branch->save();

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'BRANCH_UPDATED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'Branch',
            'entity_id' => $branch->id,
        ]);

        return response()->json($branch);
    }

    /**
     * Activate or deactivate branch.
     */
    public function changeStatus(Request $request, Branch $branch)
    {
        Gate::authorize('manageState', $branch);

        $validated = $request->validate([
            'status' => 'required|in:ACTIVE,INACTIVE',
        ]);

        if ($branch->is_headquarters && $validated['status'] === 'INACTIVE') {
            return response()->json(['message' => 'La sucursal matriz no puede desactivarse.'], 422);
        }

        $branch->status = $validated['status'];
        $branch->updated_by = $request->user()->id;
        $branch->lock_version++;
        $branch->save();

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'BRANCH_STATUS_CHANGED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'Branch',
            'entity_id' => $branch->id,
            'metadata' => ['new_status' => $branch->status],
        ]);

        return response()->json($branch);
    }
}
