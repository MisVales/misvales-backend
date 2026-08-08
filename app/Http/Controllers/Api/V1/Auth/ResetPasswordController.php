<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Mail\Security\SecurityAlertMail;
use App\Models\AuthSession;
use App\Models\User;
use App\Services\Audit\SecurityAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

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
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $email = trim(strtolower($request->email));
        $hashedToken = hash('sha256', $request->token);

        $user = User::where('normalized_email', $email)->first();
        if (! $user || $user->state !== 'ACTIVE') {
            return response()->json(['message' => 'Usuario no encontrado o inactivo.'], 404);
        }

        // Buscar el token activo usando los nombres canónicos del módulo 1.
        $resetRecord = DB::table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->where('token_hash', $hashedToken)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        // 1. Validar Token y Expiración (usando expires_at)
        if (! $resetRecord) {
            return response()->json(['message' => 'El token de recuperación es inválido o ha expirado.'], 400);
        }

        // 2. Actualizar el Hash (Argon2id por defecto en Laravel 11)
        $user->password = Hash::make($request->password);
        $user->password_changed_at = now();
        $user->save();

        // 3. Invalidar el Token (conservar el historial lógico si se requiere, pero usar borrado como en local o consumo si aplica)
        // Consumir lógicamente el token para conservar el historial como define upstream.
        DB::table('password_reset_tokens')
            ->where('id', $resetRecord->id)
            ->update(['consumed_at' => now()]);

        // 4. Revocar todas las sesiones activas (Punto 29)
        $activeSessions = AuthSession::where('user_id', $user->id)
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
        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'PASSWORD_RESET',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'user_id' => $user->id,
        ]);

        // 6. Enviar Alerta de Seguridad por Correo (Punto 45)
        Mail::to($user->email)->queue(
            new SecurityAlertMail(
                $user,
                'Contraseña Modificada',
                'La contraseña de tu cuenta ha sido modificada con éxito.',
                [
                    'ip' => $request->ip(),
                    'device' => app(SecurityAuditService::class)->parseDevice($request->userAgent()),
                    'time' => now()->toDateTimeString(),
                ]
            )
        );

        return response()->json(['message' => 'Contraseña restablecida exitosamente. Por favor, inicie sesión nuevamente.']);
    }
}
