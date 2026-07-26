<?php

namespace Tests\Feature\Modules\Configuration;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEndpointsTest extends TestCase
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

    public function test_can_create_product(): void
    {
        $payload = [
            'amount' => 1000,
            'loan_commission_rate' => 0.05,
            'interest_rate_per_fortnight' => 0.02,
            'insurance_amount' => 50,
            'fortnight_count' => 12,
        ];

        $this->withSession(['critical_actions' => [CriticalAction::PRODUCT_CREATE->value => time()]]);

        $response = $this->actingAs($this->admin)->postJson('/api/products', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.amount', '1000.0000'); // Assuming it casts to string format based on resource
    }

    public function test_cannot_create_product_without_mfa(): void
    {
        $payload = [
            'amount' => 1000,
            'loan_commission_rate' => 0.05,
            'interest_rate_per_fortnight' => 0.02,
            'insurance_amount' => 50,
            'fortnight_count' => 12,
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/products', $payload);

        $response->assertStatus(403);
    }

    public function test_can_publish_product_version(): void
    {
        $payload = [
            'amount' => 1000,
            'loan_commission_rate' => 0.05,
            'interest_rate_per_fortnight' => 0.02,
            'insurance_amount' => 50,
            'fortnight_count' => 12,
        ];

        $this->withSession(['critical_actions' => [
            CriticalAction::PRODUCT_CREATE->value => time(),
            CriticalAction::PRODUCT_VERSION_PUBLISH->value => time(),
        ]]);
        $data = $this->actingAs($this->admin)->postJson('/api/products', $payload)->json('data');

        $response = $this->actingAs($this->admin)->postJson("/api/products/{$data['product_id']}/versions/{$data['version_id']}/publish", [
            'effective_from' => now()->addMinutes(5)->toIso8601String(),
            'reason' => 'Activación inicial',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'PUBLISHED');
    }

    public function test_can_list_products(): void
    {
        $payload = [
            'amount' => 2000,
            'loan_commission_rate' => 0.05,
            'interest_rate_per_fortnight' => 0.02,
            'insurance_amount' => 50,
            'fortnight_count' => 12,
        ];

        $this->withSession(['critical_actions' => [
            CriticalAction::PRODUCT_CREATE->value => time(),
            CriticalAction::PRODUCT_VERSION_PUBLISH->value => time(),
        ]]);

        $data = $this->actingAs($this->admin)->postJson('/api/products', $payload)->json('data');

        $this->actingAs($this->admin)->postJson("/api/products/{$data['product_id']}/versions/{$data['version_id']}/publish", [
            'effective_from' => now()->addMinutes(5)->toIso8601String(),
            'reason' => 'Activación inicial',
        ]);

        $this->travel(10)->minutes();

        $response = $this->actingAs($this->admin)->getJson('/api/products');

        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json('data')));
    }
}
