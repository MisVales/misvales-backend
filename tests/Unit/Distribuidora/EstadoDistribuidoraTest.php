<?php

namespace Tests\Unit\Distribuidora;

use App\Enums\EstadoDistribuidora;
use App\Models\Distribuidora;
use PHPUnit\Framework\TestCase;

class EstadoDistribuidoraTest extends TestCase
{
    public function test_estado_no_puede_asignarse_masivamente(): void
    {
        $distribuidora = new Distribuidora([
            'application_id' => fake()->uuid(),
            'user_id' => fake()->uuid(),
            'distributor_number' => 'DIS-2026-000001',
            'branch_id' => fake()->uuid(),
            'status' => EstadoDistribuidora::ACTIVA->value,
        ]);

        self::assertNull($distribuidora->getAttribute('status'));
    }

    public function test_estados_persistidos_son_controlados(): void
    {
        self::assertSame(
            ['PENDING_ACTIVATION', 'ACTIVE', 'DISABLED'],
            array_column(EstadoDistribuidora::cases(), 'value'),
        );
    }
}
