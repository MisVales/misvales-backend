<?php

namespace App\Modules\Organization\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrganizationChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type = (string) ($this->payload['event_type'] ?? 'ORGANIZATION_CHANGED');
        $branch = $this->payload['branch_id'] ?? null;
        $reason = $this->payload['reason'] ?? null;

        $message = (new MailMessage)
            ->subject('Cambio organizacional en MisVales')
            ->greeting("Hola {$notifiable->name}")
            ->line("Se registró el evento organizacional {$type}.");

        if ($branch !== null) {
            $message->line("Sucursal relacionada: {$branch}.");
        }

        if ($reason !== null) {
            $message->line("Motivo: {$reason}.");
        }

        return $message->line('Si no reconoce este cambio, contacte al gerente general.');
    }
}
