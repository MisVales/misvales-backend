<?php

namespace App\Jobs;

use App\Models\RegistroOperacional;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PersistOperationalHttpRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 10;

    public bool $failOnTimeout = true;

    /**
     * @param  array<string, mixed>  $record
     */
    public function __construct(public readonly array $record) {}

    public function handle(): void
    {
        RegistroOperacional::query()->create($this->record);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('OPERATIONAL_LOG_PERSISTENCE_FAILED', [
            'exception' => $exception === null ? null : $exception::class,
            'request_id' => $this->record['request_id'] ?? null,
            'correlation_id' => $this->record['correlation_id'] ?? null,
            'trace_id' => $this->record['trace_id'] ?? null,
        ]);
    }
}
