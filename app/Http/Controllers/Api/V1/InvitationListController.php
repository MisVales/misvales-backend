<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountInvitation;
use App\Models\User;
use App\Services\Audit\SecurityAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Http\Traits\ReauthenticatesMfa;

class InvitationListController extends Controller
{
    use ReauthenticatesMfa;

    /**
     * GET /api/v1/invitations
     * Lista todas las invitaciones enviadas.
     */
    public function index(Request $request)
    {
        Gate::authorize('manage', User::class);

        $query = AccountInvitation::with([
            'user' => function ($q) {
                $q->select('id', 'name', 'email')->with(['roleScopes.role', 'roleScopes.branch']);
            },
            'createdBy' => function ($q) {
                $q->select('id', 'name');
            }
        ]);

        if ($request->has('state')) {
            $states = explode(',', $request->state);
            $query->whereIn('state', $states);
        }

        if ($request->has('search')) {
            $search = strtolower(trim($request->search));
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('normalized_email', 'like', "%{$search}%")
                    ->orWhereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
            });
        }

        $query->orderBy('created_at', 'desc');

        $invitations = $query->paginate(15);

        // Transformar la respuesta para aplanar los datos de usuario
        $invitations->getCollection()->transform(function ($invitation) {
            $roleName = null;
            $branchName = null;
            if ($invitation->user && $invitation->user->roleScopes->isNotEmpty()) {
                $activeScope = $invitation->user->roleScopes->where('status', 'ACTIVE')->first();
                if ($activeScope && $activeScope->role) {
                    $roleName = $activeScope->role->name;
                }
                if ($activeScope && $activeScope->branch) {
                    $branchName = $activeScope->branch->name;
                }
            }

            return [
                'id' => $invitation->id,
                'user_id' => $invitation->user_id,
                'user_email' => $invitation->user->email ?? null,
                'user_name' => $invitation->user->name ?? null,
                'role_name' => $roleName,
                'branch_name' => $branchName,
                'inviter_name' => $invitation->createdBy->name ?? null,
                'state' => $invitation->state,
                'expires_at' => $invitation->expires_at,
                'inspected_at' => $invitation->inspected_at,
                'mfa_setup_completed_at' => $invitation->mfa_setup_completed_at,
                'attempt_count' => $invitation->attempt_count,
                'created_at' => $invitation->created_at,
            ];
        });

        return response()->json($invitations);
    }

    /**
     * POST /api/v1/invitations/{invitation}/revoke
     */
    public function revoke(Request $request, AccountInvitation $invitation)
    {
        Gate::authorize('manage', User::class);
        $this->ensureRecentMfa($request);

        abort_unless(in_array($invitation->state, ['ACTIVE', 'PREPARED']), 409, 'Invitation cannot be revoked');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function() use ($invitation, $request, $validated) {
            $invitation->update([
                'state' => 'REVOKED',
                'revoked_at' => now(),
                'revoked_by' => $request->user()->id,
                'revocation_reason' => $validated['reason'],
            ]);

            app(SecurityAuditService::class)->log($request, [
                'event_type' => 'INVITATION_REVOKED',
                'severity' => 'WARNING',
                'outcome' => 'SUCCESS',
                'entity_type' => 'AccountInvitation',
                'entity_id' => $invitation->id,
                'user_id' => $invitation->user_id,
                'reason' => $validated['reason']
            ]);
        });

        return response()->json(['data' => $invitation->fresh()]);
    }
}
