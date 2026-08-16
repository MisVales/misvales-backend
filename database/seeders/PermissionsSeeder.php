<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            (new RolesAndPermissionsSeeder)->seedPermissionCatalog();
        });
    }
}
