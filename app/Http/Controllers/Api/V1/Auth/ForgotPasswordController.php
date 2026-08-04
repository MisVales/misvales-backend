<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ForgotPasswordMail;
use App\Models\User;
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
            'email' => 'required|email'
        ]);

        $email = trim(strtolower($request->email));

        // 2. Buscar usuario (Anti-Enumeración: No importa si existe o no, respondemos lo mismo)
        $user = User::where('normalized_email', $email)->first();

        if ($user && $user->state === 'ACTIVE') {
            // 3. Generar token criptográficamente seguro
            $plainToken = Str::random(60);
            $hashedToken = hash('sha256', $plainToken);

            // 4. Limpiar tokens anteriores para este usuario
            DB::table('password_reset_tokens')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->insert([
                'id' => Str::uuid(),
                'user_id' => $user->id,
                'token_hash' => $hashedToken,
                'expires_at' => now()->addMinutes(60),
                'created_at' => now()
            ]);

            // 5. Enviar el correo electrónico con el token en texto plano
            \Illuminate\Support\Facades\Mail::to($user->email)->queue(new \App\Mail\Security\PasswordRecoveryMail(
                $user, 
                $plainToken,
                [
                    'ip' => $request->ip(),
                    'device' => app(\App\Services\Audit\SecurityAuditService::class)->parseDevice($request->userAgent()),
                    'time' => now()->toDateTimeString(),
                ]
            ));
        }

        // 6. Respuesta no enumerativa (Punto 27 y 28)
        // El atacante SIEMPRE recibirá este mensaje exitoso (200 OK) sin importar si el correo existe
        return response()->json([
            'message' => 'Si el correo electrónico existe y la cuenta está activa, recibirás un mensaje con las instrucciones para recuperar tu contraseña.'
        ]);
    }
}
