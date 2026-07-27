<?php

namespace App\Modules\Notification\Domain\Events;

class InAppNotificationCreated
{
    public function __construct(
        public readonly string $notificationId,
        public readonly string $userId,
        public readonly string $correlationId,
        public readonly ?string $causationId
    ) {}
}
