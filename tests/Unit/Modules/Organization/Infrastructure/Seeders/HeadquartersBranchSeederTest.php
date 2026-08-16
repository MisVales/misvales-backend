<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Organization\Infrastructure\Seeders;

use Database\Seeders\HeadquartersBranchSeeder;
use Database\Seeders\InitialGeneralManagerSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HeadquartersBranchSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconciles_the_headquarters_without_creating_duplicates(): void
    {
        $this->seed([
            RolesSeeder::class,
            InitialGeneralManagerSeeder::class,
            HeadquartersBranchSeeder::class,
            HeadquartersBranchSeeder::class,
        ]);

        self::assertSame(1, (int) \DB::table('branches')->where('is_headquarters', true)->count());
        $this->assertDatabaseHas('branches', [
            'code' => 'MATRIZ',
            'name' => 'Sucursal Matriz',
            'address' => 'Torreón, Coahuila',
            'status' => 'ACTIVE',
            'is_headquarters' => true,
        ]);
    }
}
