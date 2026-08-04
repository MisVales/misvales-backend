<?php

namespace App\Mail\Security;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordRecoveryMail extends Mailable implements ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 60];

    public function uniqueId(): string
    {
        return $this->user->id.'_password_recovery';
    }

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
        // En un frontend SPA real, esta URL apuntaría a tu aplicación React/Vue.
        $resetUrl = url(config('app.url')."/password-reset?token={$this->token}&email={$this->user->normalized_email}");

        return new Content(
            markdown: 'emails.security.password-recovery',
            with: [
                'resetUrl' => $resetUrl,
                'context' => $this->context,
            ]
        );
    }
}
