<?php

namespace Tests\Feature\Modules\Configuration;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\AccessFoundationSeeder']);

        $adminRole = Role::query()->where('code', 'GENERAL_MANAGER')->firstOrFail();
        $this->admin = clone User::factory()->create([
            'role_id' => $adminRole->id,
            'branch_id' => null,
            'state' => AccountState::ACTIVE,
        ]);

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\ConfigurationFoundationSeeder']);

        $viewerRole = Role::query()->where('code', 'VERIFIER')->firstOrFail();
        $branch = Branch::query()->firstOrFail();

        User::factory()->create([
            'role_id' => $viewerRole->id,
            'branch_id' => $branch->id,
            'state' => AccountState::ACTIVE,
        ]);
    }

    public function test_can_create_category(): void
    {
        $payload = [
            'name' => 'Categoría Test',
            'description' => 'Categoría para pruebas automatizadas',
            'distributor_profit_rate' => 0.15,
        ];

        $this->withSession(['critical_actions' => [CriticalAction::CATEGORY_CREATE->value => time()]]);

        $response = $this->actingAs($this->admin)->postJson('/api/categories', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Categoría Test');
    }

    public function test_cannot_create_category_without_mfa(): void
    {
        $payload = [
            'name' => 'Categoría Test',
            'description' => 'Categoría para pruebas automatizadas',
            'distributor_profit_rate' => 0.15,
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/categories', $payload);

        $response->assertStatus(403);
    }

    public function test_can_publish_category_version(): void
    {
        $payload = [
            'name' => 'Cat 1',
            'description' => 'Desc',
            'distributor_profit_rate' => 0.10,
        ];

        $this->withSession(['critical_actions' => [
            CriticalAction::CATEGORY_CREATE->value => time(),
            CriticalAction::CATEGORY_VERSION_PUBLISH->value => time(),
        ]]);
        $data = $this->actingAs($this->admin)->postJson('/api/categories', $payload)->json('data');

        $response = $this->actingAs($this->admin)->postJson("/api/categories/{$data['category_id']}/versions/{$data['version_id']}/publish", [
            'effective_from' => now()->addMinutes(5)->toIso8601String(),
            'reason' => 'Activación inicial',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'PUBLISHED');
    }

    public function test_can_list_categories(): void
    {
        $payload = [
            'name' => 'Cat 2',
            'description' => 'Desc',
            'distributor_profit_rate' => 0.10,
        ];

        $this->withSession(['critical_actions' => [
            CriticalAction::CATEGORY_CREATE->value => time(),
            CriticalAction::CATEGORY_VERSION_PUBLISH->value => time(),
        ]]);

        $data = $this->actingAs($this->admin)->postJson('/api/categories', $payload)->json('data');

        $this->actingAs($this->admin)->postJson("/api/categories/{$data['category_id']}/versions/{$data['version_id']}/publish", [
            'effective_from' => now()->addMinutes(5)->toIso8601String(),
            'reason' => 'Activación inicial',
        ]);

        $this->travel(10)->minutes();

        $response = $this->actingAs($this->admin)->getJson('/api/categories');

        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json('data')));
    }
}
