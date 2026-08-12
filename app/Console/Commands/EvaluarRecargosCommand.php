<?php

namespace App\Console\Commands;

use App\Services\Recargo\ServicioEvaluacionRecargo;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class EvaluarRecargosCommand extends Command
{
    protected $signature = 'relations:evaluate-late-fees';

    protected $description = 'Evalúa recargo único después del archivo bancario';

    public function handle(ServicioEvaluacionRecargo $service): int
    {
        $this->info(json_encode($service->evaluar(CarbonImmutable::now(config('relations.timezone')))));

        return self::SUCCESS;
    }
}
