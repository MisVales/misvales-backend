<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class NotificacionEventoDominio extends Notification
{
    use Queueable;

    public function __construct(
        public readonly array $content,
        private readonly bool $critical = false,
        private readonly ?array $channels = null,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channels ?? ($this->critical ? ['database', 'mail'] : ['database']);
    }

    public function toArray(object $notifiable): array
    {
        return $this->content;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->content['title'])->line($this->content['description'])->action('Abrir MisVales', url($this->content['deep_link']));
    }
}
