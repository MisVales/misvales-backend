<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Queue;

use App\Modules\Reporting\Domain\Contracts\ReportOutboxPublisher;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportOutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PublishReportOutboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public readonly string $eventId) {}

    public function handle(ReportOutboxPublisher $publisher): void
    {
        try {
            DB::transaction(function () use ($publisher): void {
                $event = ReportOutboxEvent::query()->lockForUpdate()->find($this->eventId);
                if ($event !== null && $event->published_at === null) {
                    $publisher->publish($event);
                    $event->published_at = now('UTC');
                    $event->attempts++;
                    $event->last_error = null;
                    $event->save();
                }
            });
        } catch (Throwable $exception) {
            ReportOutboxEvent::query()->whereKey($this->eventId)->update([
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => $exception::class,
                'updated_at' => now('UTC'),
            ]);
            throw $exception;
        }
    }
}
