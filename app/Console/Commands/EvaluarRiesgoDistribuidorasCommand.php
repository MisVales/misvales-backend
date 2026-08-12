<?php

namespace App\Console\Commands;

use App\Models\Distribuidora;
use App\Services\Riesgo\ServicioMorosidadDistribuidora;
use Illuminate\Console\Command;

final class EvaluarRiesgoDistribuidorasCommand extends Command
{
    protected $signature = 'risk:evaluate-distributors';

    protected $description = 'Detecta tres relaciones incumplidas sin aplicar morosidad automáticamente';

    public function handle(ServicioMorosidadDistribuidora $s): int
    {
        $count = 0;
        Distribuidora::where('status', 'ACTIVE')->each(function ($d) use ($s, &$count) {
            if ($s->evaluar($d)) {
                $count++;
            }
        });
        $this->info("Alertas: $count");

        return self::SUCCESS;
    }
}
