<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvitationListController extends Controller
{
    /**
     * GET /api/v1/invitations
     * Lista todas las invitaciones enviadas.
     */
    public function index(Request $request)
    {
        Gate::authorize('manage', User::class);

        $query = AccountInvitation::with(['user' => function ($q) {
            $q->select('id', 'name', 'email');
        }]);

        if ($request->has('state')) {
            $query->where('state', $request->state);
        }

        if ($request->has('search')) {
            $search = strtolower(trim($request->search));
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('normalized_email', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $query->orderBy('created_at', 'desc');

        $invitations = $query->paginate(15);

        // Transformar la respuesta para aplanar los datos de usuario
        $invitations->getCollection()->transform(function ($invitation) {
            return [
                'id' => $invitation->id,
                'user_id' => $invitation->user_id,
                'user_email' => $invitation->user->email ?? null,
                'user_name' => $invitation->user->name ?? null,
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
}
