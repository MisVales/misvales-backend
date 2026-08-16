<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            PermissionsSeeder::class,
            InitialGeneralManagerSeeder::class,
            RolePermissionsSeeder::class,
            HeadquartersBranchSeeder::class,
            ConfigurationDefinitionsSeeder::class,
            InitialConfigurationVersionsSeeder::class,
            SepomexSeeder::class,
        ]);
    }
}
