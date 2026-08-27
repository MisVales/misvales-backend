<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthDiagnosticsTest extends TestCase
{
    public function test_up_muestra_diagnostico_basico_sin_exponer_autorizacion(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.124.0.10'])->withHeaders([
            'User-Agent' => 'MisVales-QA/1.0',
            'CF-Connecting-IP' => '203.0.113.10',
            'X-Forwarded-For' => '203.0.113.10, 10.0.0.1',
            'Authorization' => 'Bearer secreto-que-no-debe-aparecer',
        ])->get('/up');

        $response->assertOk()
            ->assertSee('MisVales API está disponible')
            ->assertSee('MisVales-QA/1.0')
            ->assertSeeInOrder(['IP resuelta por Laravel', '203.0.113.10'])
            ->assertDontSee('secreto-que-no-debe-aparecer');
    }
}
