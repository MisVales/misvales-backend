<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    /**
     * POST /api/v1/auth/password/reset
     * Punto 29: Recuperar contraseña, revocar sesiones y registrar evento.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:12|confirmed',
        ]);

        $email = trim(strtolower($request->email));
        $hashedToken = hash('sha256', $request->token);

        // Buscar el token en base de datos
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        // 1. Validar Token y Expiración (60 minutos)
        if (!$resetRecord || $resetRecord->token !== $hashedToken || now()->diffInMinutes($resetRecord->created_at) > 60) {
            return response()->json(['message' => 'El token de recuperación es inválido o ha expirado.'], 400);
        }

        $user = User::where('normalized_email', $email)->first();
        if (!$user || $user->state !== 'ACTIVE') {
            return response()->json(['message' => 'Usuario no encontrado o inactivo.'], 404);
        }

        // 2. Actualizar el Hash (Argon2id por defecto en Laravel 11)
        $user->password = Hash::make($request->password);
        $user->password_changed_at = now();
        $user->save();

        // 3. Invalidar el Token
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // 4. Revocar todas las sesiones activas (Punto 29)
        $activeSessions = \App\Models\AuthSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->get();

        foreach ($activeSessions as $session) {
            $session->update([
                'revoked_at' => now(),
                'revocation_reason' => 'PASSWORD_RESET',
                'revoked_by_user_id' => $user->id,
            ]);

            // Eliminar token físico de Sanctum
            DB::table('personal_access_tokens')
                ->where('token', $session->getRawOriginal('session_identifier_hash'))
                ->delete();
        }

        // Eliminar también cualquier refresh_token vivo en Redis no es trivial
        // porque sus llaves están hasheadas y no tenemos un índice en caché,
        // pero como revocamos el AuthSession maestro y los Sanctum tokens,
        // cuando intenten hacer refresh fallará la validación de `AuthSession`.

        // 5. Registrar el Evento de Seguridad centralizado (Punto 29)
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'PASSWORD_RESET',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'user_id' => $user->id,
        ]);
        
        // 6. Enviar Alerta de Seguridad por Correo (Punto 45)
        \Illuminate\Support\Facades\Mail::to($user->email)->queue(
            new \App\Mail\Security\SecurityAlertMail(
                $user,
                'Contraseña Modificada',
                'La contraseña de tu cuenta ha sido modificada con éxito.',
                [
                    'ip' => $request->ip(),
                    'device' => app(\App\Services\Audit\SecurityAuditService::class)->parseDevice($request->userAgent()),
                    'time' => now()->toDateTimeString(),
                ]
            )
        );

        return response()->json(['message' => 'Contraseña restablecida exitosamente. Por favor, inicie sesión nuevamente.']);
    }
}
