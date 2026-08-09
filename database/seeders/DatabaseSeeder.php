<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            InitialGeneralManagerSeeder::class,
        ]);

        // En una instalación limpia, el actor auditable es creado por
        // misvales:bootstrap-manager, que invoca este seeder explícitamente.
        $hasGeneralManager = User::query()
            ->whereHas('roleScopes', function ($query): void {
                $query
                    ->whereNull('revoked_at')
                    ->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', 'general_manager'));
            })
            ->exists();

        if ($hasGeneralManager) {
            $this->call(HeadquartersBranchSeeder::class);
        }
    }
}
