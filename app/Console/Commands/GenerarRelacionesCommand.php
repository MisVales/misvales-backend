<?php

namespace App\Console\Commands;

use App\Services\Relacion\ServicioGeneracionRelacion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class GenerarRelacionesCommand extends Command
{
    protected $signature = 'relations:generate {--cutoff=}';

    protected $description = 'Genera relaciones idempotentes para el corte configurado';

    public function handle(ServicioGeneracionRelacion $service): int
    {
        $timezone = config('relations.timezone');
        $cutoff = $this->option('cutoff')
            ? CarbonImmutable::parse($this->option('cutoff'), $timezone)
            : CarbonImmutable::now($timezone);
        $this->info('Relaciones generadas: '.$service->generar($cutoff));

        return self::SUCCESS;
    }
}
