<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SessionController extends Controller
{
    /**
     * GET /api/v1/me/sessions
     * Puntos 23: Lista las sesiones activas
     */
    public function index(Request $request)
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
        $currentTokenHash = hash('sha256', $request->bearerToken());
        $sessions->transform(function ($session) use ($currentTokenHash) {
            $session->is_current = ($session->getRawOriginal('session_identifier_hash') === $currentTokenHash);
            return $session;
        });

        return response()->json($sessions);
    }

    /**
     * DELETE /api/v1/me/sessions/{id}
     * Punto 24: Revoca una sesión específica validando propiedad.
     */
    public function destroy(Request $request, string $id)
    {
        $session = AuthSession::findOrFail($id);
        
        Gate::authorize('delete', $session);

        if ($session->revoked_at !== null) {
            return response()->noContent();
        }

        $session->update([
            'revoked_at' => now(),
            'revocation_reason' => 'USER_REMOTE_LOGOUT',
            'revoked_by_user_id' => $request->user()->id,
        ]);
        
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'SESSION_REVOKED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'auth_session_id' => $session->id,
            'metadata' => ['reason' => 'USER_REMOTE_LOGOUT'],
        ]);
        
        // El usuario dueño de la sesión debe tener acceso por la relación de DB, pero si no la hemos cargado:
        $userOwner = \App\Models\User::find($session->user_id);
        if ($userOwner) {
            \Illuminate\Support\Facades\Mail::to($userOwner->email)->queue(
                new \App\Mail\Security\SecurityAlertMail(
                    $userOwner,
                    'Sesión Revocada',
                    'Una de tus sesiones en '.env('APP_NAME').' ha sido revocada de forma remota.',
                    [
                        'ip' => $request->ip(),
                        'device' => app(\App\Services\Audit\SecurityAuditService::class)->parseDevice($request->userAgent()),
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
     * Punto 25: Revoca todas las DEMÁS sesiones
     */
    public function destroyOther(Request $request)
    {
        $currentTokenHash = hash('sha256', $request->bearerToken());
        
        $otherSessions = AuthSession::where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->where('session_identifier_hash', '!=', $currentTokenHash)
            ->get();

        foreach ($otherSessions as $session) {
            $session->update([
                'revoked_at' => now(),
                'revocation_reason' => 'USER_REMOTE_LOGOUT_ALL',
                'revoked_by_user_id' => $request->user()->id,
            ]);
            
            app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
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
