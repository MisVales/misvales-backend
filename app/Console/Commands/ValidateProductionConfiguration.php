<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Production\ProductionConfigurationValidator;
use Illuminate\Console\Command;

final class ValidateProductionConfiguration extends Command
{
    protected $signature = 'app:validate-production';

    protected $description = 'Fail when the effective production configuration is unsafe';

    public function handle(ProductionConfigurationValidator $validator): int
    {
        $violations = $validator->violations();
        if ($violations !== []) {
            $this->components->error('Unsafe production configuration.');
            foreach ($violations as $violation) {
                $this->line($violation);
            }

            return self::FAILURE;
        }

        $this->components->info('Production configuration is safe.');

        return self::SUCCESS;
    }
}
