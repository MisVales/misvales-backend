<?php

namespace App\Console\Commands;

use App\Models\RelacionDistribuidora;
use App\Services\Relacion\ServicioGeneracionRelacion;
use Illuminate\Console\Command;

final class RepararOcurrenciasTerminalesRelacionCommand extends Command
{
    protected $signature = 'relations:repair-terminal-occurrences {relation : UUID de la relación vigente que omitió ocurrencias terminales}';

    protected $description = 'Materializa de forma idempotente las ocurrencias terminales omitidas en una relación ya generada.';

    public function handle(ServicioGeneracionRelacion $service): int
    {
        $relation = RelacionDistribuidora::query()->find($this->argument('relation'));
        if ($relation === null) {
            $this->error('No existe la relación indicada.');

            return self::FAILURE;
        }

        $created = $service->repararOcurrenciasTerminales($relation);
        if ($created === 0) {
            $this->info('La relación ya estaba reparada o no tiene ocurrencias terminales pendientes.');

            return self::SUCCESS;
        }

        $relation->refresh();
        $this->info("Reparación completada: {$created} ocurrencia(s) terminal(es); saldo {$relation->balance}.");

        return self::SUCCESS;
    }
}
