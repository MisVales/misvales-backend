<?php

namespace App\Console\Commands;

use App\Jobs\DispatchOutboxEventJob;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process';

    protected $description = 'Barrer eventos pendientes del Outbox y encolarlos';

    public function handle()
    {
        $events = OutboxEvent::where('status', 'PENDING')->get();
        foreach ($events as $event) {
            DispatchOutboxEventJob::dispatch($event);
        }
    }
}
