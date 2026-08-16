<?php

namespace App\Console\Commands;

use App\Services\Recargo\ServicioConfiguracionRecargo;
use App\Services\Recargo\ServicioEvaluacionRecargo;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class EvaluarRecargosCommand extends Command
{
    protected $signature = 'relations:evaluate-late-fees {--scheduled : Ejecuta solo a la hora publicada vigente}';

    protected $description = 'Evalúa recargo único después del archivo bancario';

    public function handle(ServicioEvaluacionRecargo $service, ServicioConfiguracionRecargo $configuration): int
    {
        $now = CarbonImmutable::now('UTC');
        if ($this->option('scheduled') && ! $configuration->evaluacionProgramada($now)) {
            return self::SUCCESS;
        }
        $this->info(json_encode($service->evaluar($now)));

        return self::SUCCESS;
    }
}
