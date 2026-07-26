<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Contracts;

use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportOutboxEvent;

/**
 * Integration port implemented by the M17/M18 event transport.
 */
interface ReportOutboxPublisher
{
    public function publish(ReportOutboxEvent $event): void;
}
