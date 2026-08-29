<?php

namespace App\Console\Commands;

use App\Models\RelacionDistribuidora;
use App\Services\Relacion\ServicioReparacionCadenaFinanciera;
use Illuminate\Console\Command;

final class RepararCadenaFinancieraRelacionCommand extends Command
{
    protected $signature = 'relations:repair-financial-chain {relation : UUID de la relación vigente}';

    protected $description = 'Reconstruye de forma auditable una cadena trasladada sin cargos canónicos de ciclo vencido.';

    public function handle(ServicioReparacionCadenaFinanciera $service): int
    {
        $relation = RelacionDistribuidora::query()->find($this->argument('relation'));
        if ($relation === null) {
            $this->error('No existe la relación indicada.');

            return self::FAILURE;
        }

        $result = $service->reparar($relation);
        $this->info("Cadena reparada: {$result['relations']} relaciones; recargos {$result['late_fees']}; total {$result['misvales_total']}.");

        return self::SUCCESS;
    }
}
