<?php

namespace App\Mail\Security;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordRecoveryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 60];

    public function __construct(
        public User $user,
        public string $token,
        public array $context = []
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Recuperación de Contraseña',
        );
    }

    public function content(): Content
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:4200');
        $resetUrl = "{$frontendUrl}/auth/restablecer-contrasena?token={$this->token}&email={$this->user->email}";

        return new Content(
            markdown: 'emails.security.password-recovery',
            with: [
                'resetUrl' => $resetUrl,
                'context' => $this->context,
            ]
        );
    }
}
