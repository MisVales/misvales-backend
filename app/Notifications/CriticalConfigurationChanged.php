<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CriticalConfigurationChanged extends Notification implements ShouldQueue
{
    use Queueable;

    private string $key;

    private string $value;

    public function __construct(string $key, string $value)
    {
        $this->key = $key;
        $this->value = $value;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Cambio crítico de configuración',
            'message' => "La configuración '{$this->key}' ha sido actualizada.",
            'key' => $this->key,
            'new_value' => $this->value,
        ];
    }
}
