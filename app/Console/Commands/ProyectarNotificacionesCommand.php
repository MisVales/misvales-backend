<?php

namespace App\Console\Commands;

use App\Services\Notificaciones\ProyectorNotificaciones;
use Illuminate\Console\Command;

final class ProyectarNotificacionesCommand extends Command
{
    protected $signature = 'notifications:project {--limit=200}';

    protected $description = 'Proyecta eventos reales a notificaciones idempotentes';

    public function handle(ProyectorNotificaciones $projector): int
    {
        $this->info('Notificaciones creadas: '.$projector->proyectar((int) $this->option('limit')));

        return self::SUCCESS;
    }
}
