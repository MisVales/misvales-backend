<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\AsignacionClienteDistribuidora;
use App\Models\Branch;
use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class CierreOperativoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_archivo_privado_valida_mime_contexto_nombre_aleatorio_y_descarga_autorizada(): void
    {
        Storage::fake('private');
        $branch = Branch::factory()->create();
        $user = $this->user('distributor', $branch->id);
        $distributor = Distribuidora::factory()->active()->create(['user_id' => $user->id, 'branch_id' => $branch->id]);
        $client = Cliente::factory()->create(['created_by' => $user->id]);
        AsignacionClienteDistribuidora::factory()->create(['client_id' => $client->id, 'distributor_id' => $distributor->id, 'branch_id' => $branch->id, 'starts_at' => now()->subDay(), 'ends_at' => null, 'assigned_by' => $user->id]);
        Sanctum::actingAs($user);
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=', true);
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->post('/api/v1/media', ['file' => UploadedFile::fake()->createWithContent('identificacion.jpg', $jpeg), 'owner_type' => 'client', 'owner_id' => $client->id, 'purpose' => 'IDENTIFICATION']);
        $response->assertCreated()->assertJsonMissingPath('data.path')->assertJsonPath('data.validation_status', 'VALIDATED');
        $mediaId = $response->json('data.id');
        $path = DB::table('media_files')->where('id', $mediaId)->value('path');
        self::assertNotSame('identificacion.jpg', basename($path));
        Storage::disk('private')->assertExists($path);
        $this->get("/api/v1/media/{$mediaId}/download")->assertOk()->assertHeader('cache-control', 'no-store, private');
        Sanctum::actingAs($this->user('distributor', Branch::factory()->create()->id));
        $this->get("/api/v1/media/{$mediaId}/download")->assertForbidden();
    }

    public function test_archivo_con_extension_disfrazada_o_entidad_inexistente_falla_cerrado(): void
    {
        Storage::fake('private');
        $manager = $this->user('general_manager');
        Sanctum::actingAs($manager);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->post('/api/v1/media', ['file' => UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo 1;'), 'owner_type' => 'client', 'owner_id' => (string) Str::uuid(), 'purpose' => 'IDENTIFICATION'])->assertUnprocessable();
    }

    public function test_readiness_verifica_dependencias_y_metricas_no_exponen_pii(): void
    {
        Storage::fake('private');
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->twice()->andReturn(true);
        Redis::shouldReceive('connection')->twice()->with('health')->andReturn($redis);
        $this->artisan('operations:heartbeat')->assertSuccessful();
        $this->getJson('/api/v1/health/readiness')->assertOk()->assertJsonPath('status', 'ready')->assertJsonMissingPath('checks');
        $metrics = $this->get('/api/v1/metrics')->assertOk()->assertHeader('content-type', 'text/plain; version=0.0.4; charset=UTF-8')->getContent();
        self::assertStringContainsString('misvales_service_ready 1', $metrics);
        self::assertStringNotContainsString('password', strtolower($metrics));
    }

    public function test_readiness_degrada_sin_esperar_una_dependencia_redis_caida(): void
    {
        Storage::fake('private');
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->once()->andThrow(new \RuntimeException('Redis no disponible'));
        Redis::shouldReceive('connection')->once()->with('health')->andReturn($redis);
        $this->artisan('operations:heartbeat')->assertSuccessful();

        $startedAt = microtime(true);
        $this->getJson('/api/v1/health/readiness')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonMissingPath('checks');

        self::assertLessThan(1.0, microtime(true) - $startedAt);
    }

    private function user(string $roleCode, ?string $branchId = null): User
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        UserRoleScope::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => $branchId, 'assigned_by_user_id' => $user->id]);

        return $user;
    }
}
