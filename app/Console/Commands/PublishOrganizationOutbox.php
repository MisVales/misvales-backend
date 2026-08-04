<?php

namespace App\Console\Commands;

use App\Modules\Organization\Application\Events\ProcessOrganizationOutbox;
use Illuminate\Console\Command;

final class PublishOrganizationOutbox extends Command
{
    protected $signature = 'organization:publish-outbox {--limit=100}';

    protected $description = 'Publica eventos organizacionales pendientes y envía sus notificaciones';

    public function handle(ProcessOrganizationOutbox $processor): int
    {
        $processed = $processor->handle(max(1, (int) $this->option('limit')));
        $this->info("Eventos organizacionales publicados: {$processed}");

        return self::SUCCESS;
    }
}
