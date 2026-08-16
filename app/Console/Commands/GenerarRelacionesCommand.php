<?php

namespace App\Console\Commands;

use App\Services\Relacion\ServicioConfiguracionRelacion;
use App\Services\Relacion\ServicioGeneracionRelacion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class GenerarRelacionesCommand extends Command
{
    protected $signature = 'relations:generate {--cutoff=} {--scheduled : Ejecuta solo si coincide con el corte publicado vigente}';

    protected $description = 'Genera relaciones idempotentes para el corte configurado';

    public function handle(ServicioGeneracionRelacion $service, ServicioConfiguracionRelacion $configuration): int
    {
        $now = CarbonImmutable::now('UTC');
        if ($this->option('scheduled')) {
            $cutoff = $configuration->corteProgramado($now);
            if ($cutoff === null) {
                return self::SUCCESS;
            }
        } else {
            $schedule = $configuration->programacionCorte($now);
            $cutoff = $this->option('cutoff')
                ? CarbonImmutable::parse($this->option('cutoff'), $schedule['timezone'])
                : $now->setTimezone($schedule['timezone']);
        }
        $this->info('Relaciones generadas: '.$service->generar($cutoff));

        return self::SUCCESS;
    }
}
