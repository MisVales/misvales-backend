<?php

namespace Database\Seeders;

use Database\Seeders\Testing\MinimalGeographySeeder;
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
            InitialAdministratorSeeder::class,
            RolePermissionsSeeder::class,
            HeadquartersBranchSeeder::class,
            LocalDemoUsersSeeder::class,
            ConfigurationDefinitionsSeeder::class,
            InitialConfigurationVersionsSeeder::class,
            InitialCatalogSeeder::class,
            DemoProductsSeeder::class,
            DemoDistributorApplicationSeeder::class,
        ]);

        $this->call(app()->environment('testing')
            ? MinimalGeographySeeder::class
            : SepomexSeeder::class);

    }
}
