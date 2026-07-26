<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Integrations;

use App\Modules\Reporting\Domain\Contracts\ReportOutboxPublisher;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportOutboxEvent;
use RuntimeException;

final class UnavailableReportOutboxPublisher implements ReportOutboxPublisher
{
    public function publish(ReportOutboxEvent $event): void
    {
        throw new RuntimeException('The M17/M18 report outbox transport is not integrated.');
    }
}
