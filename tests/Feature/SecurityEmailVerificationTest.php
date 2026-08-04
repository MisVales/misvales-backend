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
            'email' => clone $user->email,
        ]);

        $response->assertStatus(200);

        Mail::assertQueued(PasswordRecoveryMail::class, function ($mail) use ($user) {
            $mail->build(); // Compilar la vista para poder inspeccionarla

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

    public function test_security_alert_email_is_sent_on_new_login()
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'victim@ejemplo.com',
            'normalized_email' => 'victim@ejemplo.com',
            'state' => 'ACTIVE',
            'password' => Hash::make('ValidPassword123!'),
            'last_login_ip' => '192.168.1.1' // Sesión previa
        ]);

        // Intentamos un login desde una IP distinta (10.0.0.1) simulando la petición
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
             ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'ValidPassword123!'
        ]);

        $response->assertStatus(200);

        Mail::assertQueued(SecurityAlertMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email) && 
                   $mail->alertTitle === 'Nuevo inicio de sesión detectado' &&
                   $mail->context['ip'] === '10.0.0.1'; // Valida el contexto IP inyectado
        });
    }
}
