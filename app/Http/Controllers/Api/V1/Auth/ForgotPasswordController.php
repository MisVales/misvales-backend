<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Mail\Security\PasswordRecoveryMail;
use App\Models\User;
use App\Services\Audit\SecurityAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    /**
     * POST /api/v1/auth/password/forgot
     * Puntos 27 y 28: Genera token seguro y previene enumeración de cuentas.
     */
    public function forgotPassword(Request $request)
    {
        // 1. Validación estricta del formato
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = trim(strtolower($request->email));

        // 2. Buscar usuario (Anti-Enumeración: No importa si existe o no, respondemos lo mismo)
        $user = User::where('normalized_email', $email)->first();

        if ($user && $user->state === 'ACTIVE') {
            // 3. Generar token criptográficamente seguro
            $plainToken = Str::random(60);
            $hashedToken = hash('sha256', $plainToken);

            // 4. Mantener un único token activo por usuario sin perder el contrato
            // histórico definido por la migración del módulo 1.
            DB::table('password_reset_tokens')->updateOrInsert(
                ['user_id' => $user->id, 'consumed_at' => null, 'revoked_at' => null],
                [
                    'id' => Str::uuid()->toString(),
                    'token_hash' => $hashedToken,
                    'requested_ip' => $request->ip(),
                    'expires_at' => now()->addHour(),
                    'created_at' => now(),
                ]
            );

            // 5. Enviar el correo electrónico con el token en texto plano
            Mail::to($user->email)->queue(new PasswordRecoveryMail(
                $user,
                $plainToken,
                [
                    'ip' => $request->ip(),
                    'device' => app(SecurityAuditService::class)->parseDevice($request->userAgent()),
                    'time' => now()->toDateTimeString(),
                ]
            ));
        }

        // 6. Respuesta no enumerativa (Punto 27 y 28)
        // El atacante SIEMPRE recibirá este mensaje exitoso (200 OK) sin importar si el correo existe
        return response()->json([
            'message' => 'Si el correo electrónico existe y la cuenta está activa, recibirás un mensaje con las instrucciones para recuperar tu contraseña.',
        ]);
    }
}
