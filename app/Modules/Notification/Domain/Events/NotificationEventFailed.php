<?php

namespace App\Modules\Notification\Domain\Events;

class NotificationEventFailed
{
    public function __construct(
        public readonly string $notificationEventId,
        public readonly string $outboxEventId,
        public readonly string $correlationId,
        public readonly ?string $causationId,
        public readonly string $errorCode
    ) {}
}
