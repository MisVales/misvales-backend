<?php

namespace App\Modules\Access\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SecurityEventNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $template,
        private readonly array $payload,
        public readonly string $idempotencyKey,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject())
            ->line($this->message());
        $officialUrl = $this->payload['official_url'] ?? null;
        if (is_string($officialUrl) && $this->isOfficialUrl($officialUrl)) {
            $message->action('Revisar en MisVales', $officialUrl);
        }

        return $message->line('Si no reconoce esta actividad, contacte al soporte autorizado.');
    }

    private function subject(): string
    {
        return match ($this->template) {
            'account-activation' => 'Active su cuenta de MisVales',
            'password-recovery' => 'Recuperación de acceso a MisVales',
            default => 'Alerta de seguridad de MisVales',
        };
    }

    private function message(): string
    {
        return match ($this->template) {
            'account-activation' => 'Use exclusivamente el enlace oficial para completar la activación.',
            'password-recovery' => 'Use exclusivamente el enlace oficial para completar la recuperación.',
            default => 'Detectamos un evento de seguridad que requiere su atención.',
        };
    }

    private function isOfficialUrl(string $url): bool
    {
        $official = parse_url((string) config('app.frontend_url', config('app.url')));
        $candidate = parse_url($url);

        return is_array($official)
            && is_array($candidate)
            && ($candidate['scheme'] ?? null) === ($official['scheme'] ?? null)
            && ($candidate['host'] ?? null) === ($official['host'] ?? null)
            && ($candidate['port'] ?? null) === ($official['port'] ?? null);
    }
}
