<?php

namespace App\Modules\Notification\Domain\Events;

class EmailDeliverySent
{
    public function __construct(
        public readonly string $emailDeliveryId,
        public readonly string $correlationId,
        public readonly ?string $causationId,
        public readonly string $providerMessageId
    ) {}
}
