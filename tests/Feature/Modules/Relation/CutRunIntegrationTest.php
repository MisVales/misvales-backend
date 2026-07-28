<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Relation;

use App\Modules\Relation\Domain\Enums\CutRunStatus;
use App\Modules\Relation\Infrastructure\Persistence\Models\CutRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CutRunIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prevents_duplicate_cut_runs_on_same_date()
    {
        CutRun::create([
            'cut_date' => '2026-07-25',
            'business_timezone' => 'America/Monterrey',
            'status' => CutRunStatus::PENDIENTE,
            'configuration_snapshot' => ['mock' => true],
            'started_at' => now(),
            'trigger_type' => 'SCHEDULED',
        ]);

        $this->expectException(QueryException::class);

        // Intenta crear otra corrida para el mismo día
        CutRun::create([
            'cut_date' => '2026-07-25',
            'business_timezone' => 'America/Monterrey',
            'status' => CutRunStatus::PENDIENTE,
            'configuration_snapshot' => ['mock' => true],
            'started_at' => now(),
            'trigger_type' => 'SCHEDULED',
        ]);
    }
}
