<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ActivationInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public $rawToken;

    public $activationUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, string $rawToken)
    {
        $this->user = $user;
        $this->rawToken = $rawToken;

        $frontendUrl = config('production.frontend_url');
        $this->activationUrl = rtrim($frontendUrl, '/').'/activar-cuenta?token='.urlencode($rawToken);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitación para activar tu cuenta en MisVales',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: '
            <div style="font-family: sans-serif; padding: 20px;">
                <h2>Hola, '.htmlspecialchars($this->user->name).'</h2>
                <p>Has sido invitado a unirte a <strong>MisVales</strong>.</p>
                <p>Para activar tu cuenta y configurar tu seguridad (Contraseña y Autenticador), haz clic en el siguiente enlace:</p>
                <p>
                    <a href="'.htmlspecialchars($this->activationUrl).'" 
                       style="display: inline-block; padding: 10px 20px; background-color: #007bff; color: #fff; text-decoration: none; border-radius: 5px;">
                        Activar mi cuenta
                    </a>
                </p>
                <p>Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
                <p><small>'.htmlspecialchars($this->activationUrl).'</small></p>
                <p><em>Este enlace es de un solo uso y expirará pronto.</em></p>
            </div>
            '
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
