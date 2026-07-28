<?php

namespace Database\Seeders\Organization;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HeadquartersBranchSeeder extends Seeder
{
    public function run(): void
    {
        $exists = DB::table('branches')->where('is_headquarters', true)->exists();

        if (! $exists) {
            DB::table('branches')->insert([
                'public_id' => Str::uuid(),
                'name' => 'Matriz Torreón',
                'is_headquarters' => true,
                'city' => 'Torreón',
                'is_active' => true,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        }
    }
}
