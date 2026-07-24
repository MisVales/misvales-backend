<?php

namespace App\Modules\Access\Application\Security;

interface SecurityNotificationSender
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(string $recipient, string $template, array $payload, string $idempotencyKey): ?string;
}
