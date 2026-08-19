<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SolicitudDistribuidora;

use App\Services\SolicitudDistribuidora\CatalogoVehiculosSolicitud;
use Tests\TestCase;

final class CatalogoVehiculosSolicitudTest extends TestCase
{
    public function test_it_returns_a_curated_catalog_in_spanish_without_external_requests(): void
    {
        $catalog = app(CatalogoVehiculosSolicitud::class)->obtener();

        self::assertContains('Toyota', $catalog['brands']);
        self::assertContains('SUV', $catalog['vehicle_types']);
        self::assertContains('Motocicleta', $catalog['vehicle_types']);
        self::assertNotContains('Incomplete', $catalog['vehicle_types']);
    }
}
