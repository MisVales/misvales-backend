<?php

namespace Tests\Feature\Modules\Configuration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedemptionPeriodEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\AccessFoundationSeeder']);

        $adminRole = \App\Modules\Access\Infrastructure\Persistence\Models\Role::query()->where('code', 'GENERAL_MANAGER')->firstOrFail();
        $this->admin = clone User::factory()->create([
            'role_id' => $adminRole->id,
            'branch_id' => null,
            'state' => \App\Modules\Access\Domain\Accounts\AccountState::ACTIVE,
        ]);

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\ConfigurationFoundationSeeder']);

        $viewerRole = \App\Modules\Access\Infrastructure\Persistence\Models\Role::query()->where('code', 'VERIFIER')->firstOrFail();
        $branch = \App\Modules\Access\Infrastructure\Persistence\Models\Branch::query()->firstOrFail();

        $this->viewer = clone User::factory()->create([
            'role_id' => $viewerRole->id,
            'branch_id' => $branch->id,
            'state' => \App\Modules\Access\Domain\Accounts\AccountState::ACTIVE,
        ]);
    }

    public function test_can_create_redemption_period(): void
    {
        $payload = [
            'starts_at' => now()->addDays(1)->toIso8601String(),
            'ends_at' => now()->addDays(15)->toIso8601String(),
        ];

        $this->withSession(['critical_actions' => [\App\Modules\Access\Domain\Authorization\CriticalAction::REDEMPTION_PERIOD_CREATE->value => time()]]);

        $response = $this->actingAs($this->admin)->postJson('/api/redemption-periods', $payload);

        $response->assertStatus(201);
    }

    public function test_cannot_create_redemption_period_without_mfa(): void
    {
        $payload = [
            'starts_at' => now()->addDays(1)->toIso8601String(),
            'ends_at' => now()->addDays(15)->toIso8601String(),
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/redemption-periods', $payload);

        $response->assertStatus(403);
    }

    public function test_can_publish_redemption_period(): void
    {
        $payload = [
            'starts_at' => now()->addDays(1)->toIso8601String(),
            'ends_at' => now()->addDays(15)->toIso8601String(),
        ];
        
        $this->withSession(['critical_actions' => [
            \App\Modules\Access\Domain\Authorization\CriticalAction::REDEMPTION_PERIOD_CREATE->value => time(),
            \App\Modules\Access\Domain\Authorization\CriticalAction::REDEMPTION_PERIOD_PUBLISH->value => time(),
        ]]);
        $data = $this->actingAs($this->admin)->postJson('/api/redemption-periods', $payload)->json('data');

        $response = $this->actingAs($this->admin)->postJson("/api/redemption-periods/{$data['id']}/publish", [
            'reason' => 'Activación de quincena',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'PUBLISHED');
    }

    public function test_can_list_redemption_periods(): void
    {
        $payload = [
            'starts_at' => now()->addDays(1)->toIso8601String(),
            'ends_at' => now()->addDays(15)->toIso8601String(),
        ];
        
        $this->withSession(['critical_actions' => [
            \App\Modules\Access\Domain\Authorization\CriticalAction::REDEMPTION_PERIOD_CREATE->value => time(),
            \App\Modules\Access\Domain\Authorization\CriticalAction::REDEMPTION_PERIOD_PUBLISH->value => time(),
        ]]);
        
        $data = $this->actingAs($this->admin)->postJson('/api/redemption-periods', $payload)->json('data');

        $this->actingAs($this->admin)->postJson("/api/redemption-periods/{$data['id']}/publish", [
            'reason' => 'Activación de quincena',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/redemption-periods');

        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json('data')));
    }
}
