<?php

declare(strict_types=1);

namespace Database\Seeders\Testing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MinimalGeographySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('MinimalGeographySeeder solo puede ejecutarse en testing.');
        }

        $timestamp = now();

        DB::table('estados')->updateOrInsert(
            ['id' => 1],
            ['name' => 'Coahuila de Zaragoza', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        );
        DB::table('municipios')->updateOrInsert(
            ['id' => 1],
            ['estado_id' => 1, 'name' => 'Torreón', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        );
        DB::table('codigos_postales')->updateOrInsert(
            ['id' => 1],
            ['municipio_id' => 1, 'code' => '27000', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        );
        DB::table('colonias')->updateOrInsert(
            ['id' => 1],
            [
                'codigo_postal_id' => 1,
                'name' => 'Centro',
                'settlement_type' => 'Colonia',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        );
    }
}
