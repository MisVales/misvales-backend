<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Testing\LocalTestingUsersSeeder;
use Illuminate\Database\Seeder;

final class LocalDevSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(LocalTestingUsersSeeder::class);
    }
}
