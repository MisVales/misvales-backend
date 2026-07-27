<?php

declare(strict_types=1);

namespace App\Modules\Relation\Infrastructure\Console;

use Illuminate\Console\Command;
use App\Modules\Relation\Application\Commands\StartCut\StartCutCommand;
use App\Modules\Relation\Application\Commands\StartCut\StartCutHandler;
use Carbon\CarbonImmutable;
use Throwable;

class RunCutCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'relations:run-cut {--date= : Fecha operativa manual en formato Y-m-d}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ejecuta el corte global de relaciones financieras';

    /**
     * Execute the console command.
     */
    public function handle(StartCutHandler $handler): int
    {
        try {
            $dateInput = $this->option('date');
            $operativeDate = $dateInput 
                ? CarbonImmutable::parse($dateInput, 'America/Monterrey')
                : CarbonImmutable::now('America/Monterrey');

            $triggerType = $dateInput ? 'AUTHORIZED_RETRY' : 'SCHEDULED';
            
            $this->info("Iniciando corte para la fecha operativa: {$operativeDate->format('Y-m-d')}");

            $command = new StartCutCommand($operativeDate, $triggerType, null);
            $handler->handle($command);

            $this->info('Corte iniciado correctamente.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Error al iniciar el corte: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
