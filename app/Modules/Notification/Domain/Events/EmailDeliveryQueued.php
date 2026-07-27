<?php

namespace App\Modules\Notification\Domain\Events;

class EmailDeliveryQueued
{
    public function __construct(
        public readonly string $emailDeliveryId,
        public readonly string $correlationId,
        public readonly ?string $causationId
    ) {}
}
