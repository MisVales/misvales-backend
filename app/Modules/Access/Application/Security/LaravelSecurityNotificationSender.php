<?php

namespace App\Modules\Access\Application\Security;

use App\Modules\Access\Infrastructure\Notifications\SecurityEventNotification;
use Illuminate\Support\Facades\Notification;

final class LaravelSecurityNotificationSender implements SecurityNotificationSender
{
    public function send(string $recipient, string $template, array $payload, string $idempotencyKey): string
    {
        Notification::route('mail', $recipient)->notify(
            new SecurityEventNotification($template, $payload, $idempotencyKey),
        );

        return $idempotencyKey;
    }
}
