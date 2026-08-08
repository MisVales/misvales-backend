<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ReauthenticatesMfa;
use App\Mail\ActivationInvitationMail;
use App\Mail\Security\SecurityAlertMail;
use App\Models\AccountInvitation;
use App\Models\AuthSession;
use App\Models\MfaCredential;
use App\Models\User;
use App\Services\Audit\SecurityAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends Controller
{
    use ReauthenticatesMfa;

    /**
     * GET /api/v1/users
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', User::class);

        $query = User::with('roleScopes.role');

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

        if ($request->has('role_id')) {
            $query->whereHas('roleScopes', function ($q) use ($request) {
                $q->where('role_id', $request->role_id)->where('status', 'ACTIVE');
            });
        }

        if ($request->has('branch_id')) {
            $query->whereHas('roleScopes', function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id)->where('status', 'ACTIVE');
            });
        }

        return response()->json($query->paginate(15));
    }

    /**
     * POST /api/v1/users
     * Crea el usuario y opcionalmente le asigna un rol y envía la invitación.
     */
    public function store(Request $request, \App\Services\Auth\RoleAssignmentPolicyService $policyService)
    {
        Gate::authorize('create', User::class);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role_id' => 'nullable|string',
            'branch_id' => 'nullable|uuid',
            'send_invitation' => 'nullable|boolean',
        ]);

        return DB::transaction(function () use ($request, $policyService) {
            $email = trim(strtolower($request->email));

            $user = User::create([
                'id' => Str::uuid(),
                'name' => $request->name,
                'email' => $request->email,
                'normalized_email' => $email,
                'password' => '',
                'state' => 'INVITED',
            ]);

            app(SecurityAuditService::class)->log($request, [
                'event_type' => 'INVITATION_CREATED',
                'severity' => 'INFO',
                'outcome' => 'SUCCESS',
                'entity_type' => 'User',
                'entity_id' => $user->id,
                'metadata' => ['email' => $email],
            ]);

            // 2. Asignar rol si se proporcionó
            if ($request->filled('role_id')) {
                $roleInput = $request->role_id;
                $role = Str::isUuid($roleInput)
                    ? \App\Models\Role::where('id', $roleInput)->firstOrFail()
                    : \App\Models\Role::where('code', $roleInput)->firstOrFail();

                $validationResult = $policyService->validateAssignment(
                    $request->user(),
                    $user,
                    $role,
                    $request->branch_id
                );

                if ($validationResult !== true) {
                    abort(403, 'Error al asignar rol: ' . $validationResult);
                }

                $assignment = \App\Models\UserRoleScope::create([
                    'id' => Str::uuid(),
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'branch_id' => $request->branch_id,
                    'assigned_by_user_id' => $request->user()->id,
                    'assigned_at' => now(),
                    'scope_type' => $request->branch_id ? 'BRANCH' : 'GLOBAL',
                    'status' => 'ACTIVE',
                ]);

                app(SecurityAuditService::class)->log($request, [
                    'event_type' => 'ROLE_ASSIGNED',
                    'severity' => 'INFO',
                    'outcome' => 'SUCCESS',
                    'entity_type' => 'UserRoleScope',
                    'entity_id' => $assignment->id,
                    'user_id' => $user->id,
                    'branch_id' => $request->branch_id,
                    'metadata' => ['role_id' => $role->id],
                ]);
            }

            // 3. Enviar invitación si se solicitó
            if ($request->boolean('send_invitation')) {
                $user->state = 'PENDING_ACTIVATION';
                $user->save();

                $plainToken = Str::random(60);
                $tokenHash = hash('sha256', $plainToken);

                AccountInvitation::create([
                    'id' => Str::uuid(),
                    'user_id' => $user->id,
                    'token_hash' => $tokenHash,
                    'expires_at' => now()->addHours(48),
                ]);

                Mail::to($user->email)->queue(new ActivationInvitationMail($user, $plainToken));

                app(SecurityAuditService::class)->log($request, [
                    'event_type' => 'INVITATION_SENT',
                    'severity' => 'INFO',
                    'outcome' => 'SUCCESS',
                    'entity_type' => 'User',
                    'entity_id' => $user->id,
                ]);
            }

            return response()->json(['message' => 'Usuario procesado exitosamente.', 'user' => $user->load('roleScopes.role')], 201);
        });
    }

    /**
     * GET /api/v1/users/{id}
     */
    public function show(Request $request, string $id)
    {
        $user = User::with(['roleScopes.role'])->findOrFail($id);
        Gate::authorize('view', $user);

        $user->mfa_status = MfaCredential::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->whereNotNull('confirmed_at')
            ->exists();

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

        if (! in_array($user->state, ['INVITED', 'PENDING_ACTIVATION'])) {
            return response()->json(['message' => 'El usuario ya no está pendiente de activación ni invitado.'], 400);
        }

        $user->state = 'PENDING_ACTIVATION';
        $user->save();

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

        app(SecurityAuditService::class)->log($request, [
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

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'ACCOUNT_BLOCKED',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        Mail::to($user->email)->queue(
            new SecurityAlertMail(
                $user,
                'Cuenta Bloqueada',
                'Tu cuenta ha sido suspendida temporalmente por un administrador del sistema por motivos de seguridad.',
                [
                    'time' => now()->toDateTimeString(),
                    'assignment_reason' => 'Violación de políticas de seguridad / Revisión manual',
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

        app(SecurityAuditService::class)->log($request, [
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

        $reauthResult = $this->requireMfaReauth($request);
        if ($reauthResult !== true) {
            return $reauthResult;
        }

        $user->state = 'DISABLED';
        $user->disabled_at = now();
        $user->save();

        $this->revokeUserSessions($user->id, 'USER_DISABLED_BY_ADMIN', $request->user()->id);

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'ACCOUNT_DISABLED',
            'severity' => 'CRITICAL',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        Mail::to($user->email)->queue(
            new SecurityAlertMail(
                $user,
                'Cuenta Inhabilitada Permanentemente',
                'Lamentamos informarte que tu cuenta ha sido inhabilitada de forma permanente y ya no tendrás acceso a la plataforma.',
                [
                    'time' => now()->toDateTimeString(),
                ]
            )
        );

        return response()->json(['message' => 'Usuario inhabilitado. Se cerraron sus sesiones.']);
    }

    /**
     * POST /api/v1/users/{id}/enable
     */
    public function enable(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        Gate::authorize('manage', User::class);

        if ($user->state !== 'DISABLED') {
            return response()->json(['message' => 'El usuario no está inhabilitado.'], 400);
        }

        $reauthResult = $this->requireMfaReauth($request);
        if ($reauthResult !== true) {
            return $reauthResult;
        }

        $user->state = 'ACTIVE';
        $user->disabled_at = null;
        $user->disabled_reason = null;
        $user->save();

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'ACCOUNT_ENABLED',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        Mail::to($user->email)->queue(
            new SecurityAlertMail(
                $user,
                'Cuenta Reactivada',
                'Tu cuenta ha sido reactivada por un administrador del sistema. Ya puedes acceder nuevamente a la plataforma.',
                [
                    'time' => now()->toDateTimeString(),
                ]
            )
        );

        return response()->json(['message' => 'Usuario habilitado exitosamente.']);
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

    /**
     * POST /api/v1/users/{id}/require-password-change
     */
    public function requirePasswordChange(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        Gate::authorize('manage', User::class);

        $user->require_password_change = true;
        $user->save();

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'PASSWORD_CHANGE_REQUIRED',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        return response()->json(['message' => 'Se requerirá que el usuario cambie su contraseña en el próximo inicio de sesión.']);
    }
}
