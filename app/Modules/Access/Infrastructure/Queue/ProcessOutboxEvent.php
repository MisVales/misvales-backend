<?php

namespace App\Modules\Access\Infrastructure\Queue;

use App\Modules\Access\Application\Security\OutboxProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessOutboxEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 900];

    public function __construct(public readonly int $eventId) {}

    public function handle(OutboxProcessor $processor): void
    {
        $processor->process($this->eventId);
    }
}
