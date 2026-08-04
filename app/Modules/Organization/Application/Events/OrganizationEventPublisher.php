<?php

namespace App\Modules\Organization\Application\Events;

use App\Modules\Organization\Domain\Events\OrganizationEvent;

interface OrganizationEventPublisher
{
    public function publish(OrganizationEvent $event): void;
}
