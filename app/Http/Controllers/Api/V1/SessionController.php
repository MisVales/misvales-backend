<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ReauthenticatesMfa;
use App\Mail\Security\SecurityAlertMail;
use App\Models\AuthSession;
use App\Models\User;
use App\Services\Audit\SecurityAuditService;
use App\Services\Auth\SessionTokenIdentifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;

class SessionController extends Controller
{
    use ReauthenticatesMfa;

    /**
     * GET /api/v1/me/sessions
     * Control 23: Lista las sesiones activas.
     */
    public function index(Request $request, SessionTokenIdentifier $tokenIdentifier)
    {
        $sessions = AuthSession::where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('last_activity_at', 'desc')
            ->get();

        // Ocultar el hash real por seguridad
        $sessions->makeHidden(['session_identifier_hash']);

        // Marcar cuál es la sesión actual (opcional pero útil para UX)
        $currentTokenHashes = array_values(array_unique(array_filter([
            $tokenIdentifier->current($request),
            $tokenIdentifier->legacy($request),
        ])));
        $sessions->transform(function ($session) use ($currentTokenHashes) {
            $session->is_current = in_array($session->getRawOriginal('session_identifier_hash'), $currentTokenHashes, true);

            return $session;
        });

        return response()->json($sessions);
    }

    /**
     * DELETE /api/v1/me/sessions/{id}
     * Control 24: Revoca una sesión específica validando propiedad.
     */
    public function destroy(Request $request, string $id)
    {
        $session = AuthSession::findOrFail($id);

        Gate::authorize('delete', $session);

        $reauthResult = $this->requireMfaReauth($request);
        if ($reauthResult !== true) {
            return $reauthResult;
        }

        if ($session->revoked_at !== null) {
            return response()->noContent();
        }

        $session->update([
            'revoked_at' => now(),
            'revocation_reason' => 'USER_REMOTE_LOGOUT',
            'revoked_by_user_id' => $request->user()->id,
        ]);

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'SESSION_REVOKED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'auth_session_id' => $session->id,
            'metadata' => ['reason' => 'USER_REMOTE_LOGOUT'],
        ]);

        // El usuario dueño de la sesión debe tener acceso por la relación de DB, pero si no la hemos cargado:
        $userOwner = User::find($session->user_id);
        if ($userOwner) {
            Mail::to($userOwner->email)->queue(
                new SecurityAlertMail(
                    $userOwner,
                    'Sesión Revocada',
                    'Una de tus sesiones en '.config('app.name').' ha sido revocada de forma remota.',
                    [
                        'ip' => $request->ip(),
                        'device' => app(SecurityAuditService::class)->parseDevice($request->userAgent()),
                        'time' => now()->toDateTimeString(),
                    ]
                )
            );
        }

        // Revocar el token Sanctum asociado en la BD
        DB::table('personal_access_tokens')
            ->where('token', $session->getRawOriginal('session_identifier_hash'))
            ->delete();

        return response()->noContent();
    }

    /**
     * DELETE /api/v1/me/sessions
     * Control 25: Revoca todas las demás sesiones.
     */
    public function destroyOther(Request $request, SessionTokenIdentifier $tokenIdentifier)
    {
        $reauthResult = $this->requireMfaReauth($request);
        if ($reauthResult !== true) {
            return $reauthResult;
        }

        $currentTokenHashes = array_values(array_unique(array_filter([
            $tokenIdentifier->current($request),
            $tokenIdentifier->legacy($request),
        ])));

        $otherSessions = AuthSession::where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->whereNotIn('session_identifier_hash', $currentTokenHashes)
            ->get();

        foreach ($otherSessions as $session) {
            $session->update([
                'revoked_at' => now(),
                'revocation_reason' => 'USER_REMOTE_LOGOUT_ALL',
                'revoked_by_user_id' => $request->user()->id,
            ]);

            app(SecurityAuditService::class)->log($request, [
                'event_type' => 'SESSION_REVOKED',
                'severity' => 'INFO',
                'outcome' => 'SUCCESS',
                'auth_session_id' => $session->id,
                'metadata' => ['reason' => 'USER_REMOTE_LOGOUT_ALL'],
            ]);

            DB::table('personal_access_tokens')
                ->where('token', $session->getRawOriginal('session_identifier_hash'))
                ->delete();
        }

        return response()->noContent();
    }
}
