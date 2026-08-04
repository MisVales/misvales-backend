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

class SecurityAlertMail extends Mailable implements ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 60];

    public function uniqueId(): string
    {
        return $this->user->id.'_'.hash('sha256', $this->alertTitle);
    }

    public function __construct(
        public User $user,
        public string $alertTitle,
        public string $alertMessage,
        public array $context = []
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Alerta de Seguridad: '.$this->alertTitle,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.security.alert',
            with: [
                'title' => $this->alertTitle,
                'message' => $this->alertMessage,
                'context' => $this->context,
            ]
        );
    }
}
