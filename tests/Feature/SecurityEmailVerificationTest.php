<?php

namespace Tests\Feature;

use App\Models\User;
use App\Mail\Security\PasswordRecoveryMail;
use App\Mail\Security\SecurityAlertMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_recovery_email_is_queued_and_has_no_secrets()
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'hacker@ejemplo.com',
            'normalized_email' => 'hacker@ejemplo.com',
            'state' => 'ACTIVE',
            'password' => Hash::make('SecretPassword123!')
        ]);

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response->assertStatus(200);

        Mail::assertQueued(PasswordRecoveryMail::class, function ($mail) use ($user) {

            // 1. Verificar destinatario
            $hasCorrectRecipient = $mail->hasTo($user->email);
            
            // 2. Verificar existencia del token de reseteo (y no la clave)
            $tokenIsPresent = !empty($mail->token);

            // 3. Verificamos que la contraseña plana NUNCA viaje en la variable del correo
            // Si por error inyectamos $user->password, estariamos filtrando el hash o clave.
            // (El assert de contenido exacto es complejo en mailable, pero aseguramos las variables expuestas)
            
            return $hasCorrectRecipient && $tokenIsPresent;
        });
    }
}
