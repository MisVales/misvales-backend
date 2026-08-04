<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\ActivationInvitationMail;
use App\Models\AccountInvitation;
use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * GET /api/v1/users
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', User::class);

        $query = User::query();

        if ($request->has('search')) {
            $search = strtolower(trim($request->search));
            $query->where(function ($q) use ($search) {
                $q->where('normalized_email', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->has('state')) {
            $query->where('state', $request->state);
        }

        return response()->json($query->paginate(15));
    }

    /**
     * POST /api/v1/users
     */
    public function store(Request $request)
    {
        Gate::authorize('create', User::class);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
        ]);

        $email = trim(strtolower($request->email));

        $user = User::create([
            'id' => Str::uuid(),
            'name' => $request->name,
            'email' => $request->email, // Conservar capitalización original para UI
            'normalized_email' => $email,
            'password' => '', // Nace sin contraseña, la crea en la activación
            'state' => 'PENDING_ACTIVATION',
        ]);
        
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'INVITATION_CREATED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'metadata' => ['email' => $email],
        ]);

        return response()->json(['message' => 'Usuario creado exitosamente.', 'user' => $user], 201);
    }

    /**
     * GET /api/v1/users/{id}
     */
    public function show(Request $request, string $id)
    {
        $user = User::with(['roleScopes.role'])->findOrFail($id);
        Gate::authorize('view', $user);

        return response()->json($user);
    }

    /**
     * PATCH /api/v1/users/{id}
     */
    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        Gate::authorize('update', $user);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            // El cambio de correo electrónico suele implicar re-verificación, por ahora solo nombre.
        ]);

        if ($request->has('name')) {
            $user->name = $request->name;
        }

        $user->save();

        return response()->json(['message' => 'Usuario actualizado.', 'user' => $user]);
    }

    /**
     * POST /api/v1/users/{id}/invite
     */
    public function invite(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        Gate::authorize('manage', User::class);

        if ($user->state !== 'PENDING_ACTIVATION') {
            return response()->json(['message' => 'El usuario ya no está pendiente de activación.'], 400);
        }

        $plainToken = Str::random(60);
        $tokenHash = hash('sha256', $plainToken);

        // Limpiar invitaciones previas
        AccountInvitation::where('user_id', $user->id)->delete();

        AccountInvitation::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'expires_at' => now()->addHours(48),
        ]);

        // Enviar correo (Mailable ya existente)
        Mail::to($user->email)->queue(new ActivationInvitationMail($user, $plainToken));
        
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'INVITATION_SENT',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        return response()->json(['message' => 'Invitación de activación enviada exitosamente.']);
    }

    /**
     * POST /api/v1/users/{id}/block
     */
    public function block(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        Gate::authorize('manage', User::class);

        if ($user->state === 'DISABLED') {
            return response()->json(['message' => 'No se puede bloquear a un usuario inhabilitado permanentemente.'], 400);
        }

        $user->state = 'BLOCKED';
        $user->save();

        $this->revokeUserSessions($user->id, 'USER_BLOCKED_BY_ADMIN', $request->user()->id);
        
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'ACCOUNT_BLOCKED',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);
        
        \Illuminate\Support\Facades\Mail::to($user->email)->queue(
            new \App\Mail\Security\SecurityAlertMail(
                $user,
                'Cuenta Bloqueada',
                'Tu cuenta ha sido suspendida temporalmente por un administrador del sistema por motivos de seguridad.',
                [
                    'time' => now()->toDateTimeString(),
                    'reason' => 'Violación de políticas de seguridad / Revisión manual',
                ]
            )
        );

        return response()->json(['message' => 'Usuario bloqueado exitosamente. Se cerraron sus sesiones.']);
    }

    /**
     * POST /api/v1/users/{id}/unblock
     */
    public function unblock(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        Gate::authorize('manage', User::class);

        if ($user->state !== 'BLOCKED') {
            return response()->json(['message' => 'El usuario no está bloqueado.'], 400);
        }

        $user->state = 'ACTIVE';
        $user->locked_until = null;
        $user->failed_login_attempts = 0;
        $user->save();
        
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'ACCOUNT_UNBLOCKED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        return response()->json(['message' => 'Usuario desbloqueado exitosamente.']);
    }

    /**
     * POST /api/v1/users/{id}/disable
     */
    public function disable(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        Gate::authorize('manage', User::class);

        $user->state = 'DISABLED';
        $user->disabled_at = now();
        $user->save();

        $this->revokeUserSessions($user->id, 'USER_DISABLED_BY_ADMIN', $request->user()->id);
        
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'ACCOUNT_DISABLED',
            'severity' => 'CRITICAL',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);
        
        \Illuminate\Support\Facades\Mail::to($user->email)->queue(
            new \App\Mail\Security\SecurityAlertMail(
                $user,
                'Cuenta Inhabilitada Permanentemente',
                'Lamentamos informarte que tu cuenta ha sido inhabilitada de forma permanente y ya no tendrás acceso a la plataforma.',
                [
                    'time' => now()->toDateTimeString(),
                ]
            )
        );

        return response()->json(['message' => 'Usuario inhabilitado permanentemente. Se cerraron sus sesiones.']);
    }

    /**
     * Helper Privado: Expulsa al usuario destruyendo sus sesiones.
     */
    private function revokeUserSessions(string $targetUserId, string $reason, string $actorId)
    {
        $sessions = AuthSession::where('user_id', $targetUserId)
            ->whereNull('revoked_at')
            ->get();

        foreach ($sessions as $session) {
            $session->update([
                'revoked_at' => now(),
                'revocation_reason' => $reason,
                'revoked_by_user_id' => $actorId,
            ]);

            DB::table('personal_access_tokens')
                ->where('token', $session->getRawOriginal('session_identifier_hash'))
                ->delete();
        }
    }
}
