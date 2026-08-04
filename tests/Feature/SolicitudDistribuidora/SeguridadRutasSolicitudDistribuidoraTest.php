<?php

namespace Tests\Feature\SolicitudDistribuidora;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SeguridadRutasSolicitudDistribuidoraTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_rutas_rechazan_usuarios_inactivos(): void
    {
        $email = Str::uuid().'@example.test';
        $usuario = User::factory()->create(['state' => 'DISABLED', 'email' => $email, 'normalized_email' => $email]);
        Sanctum::actingAs($usuario);

        $this->getJson('/api/v1/distributor-applications')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'ACCOUNT_INACTIVE');
    }

    public function test_las_rutas_exigen_mfa_completado(): void
    {
        $email = Str::uuid().'@example.test';
        $usuario = User::factory()->create(['state' => 'ACTIVE', 'email' => $email, 'normalized_email' => $email]);
        Sanctum::actingAs($usuario);

        $this->getJson('/api/v1/distributor-applications')
            ->assertForbidden()
            ->assertJsonPath('error', 'INVALID_MFA');
    }
}
