<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class ConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ConfigurationDefinitionsSeeder::class,
            InitialConfigurationVersionsSeeder::class,
        ]);
    }
}
