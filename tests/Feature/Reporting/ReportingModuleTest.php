<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use App\Modules\Reporting\Application\Services\ReportAuditRecorder;
use App\Modules\Reporting\Application\Services\ReportRegistry;
use App\Modules\Reporting\Application\Services\ReportResultProtector;
use App\Modules\Reporting\Domain\Contracts\ReportReadModelGateway;
use App\Modules\Reporting\Domain\Contracts\ReportResultStoreInterface;
use App\Modules\Reporting\Domain\Enums\ReportRunStatus;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRun;
use App\Modules\Reporting\Infrastructure\Queue\ExecuteReportRunJob;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeReportReadModelGateway;
use Tests\TestCase;

final class ReportingModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_catalog_is_limited_by_role_and_denies_cashier_by_default(): void
    {
        $manager = $this->user(RoleCode::GENERAL_MANAGER);
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonCount(21, 'data')
            ->assertJsonMissing(['roles'])
            ->assertJsonMissing(['sourceModules']);

        Sanctum::actingAs($this->user(RoleCode::DISTRIBUTOR));
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonCount(13, 'data');

        Sanctum::actingAs($this->user(RoleCode::CASHIER));
        $this->getJson('/api/v1/reports')->assertForbidden()
            ->assertJsonPath('error.code', 'REPORT_ACCESS_DENIED');
        $this->assertDatabaseHas('report_query_events', ['outcome' => 'DENIED', 'error_code' => 'REPORT_ACCESS_DENIED']);
    }

    public function test_sync_report_uses_one_scope_for_rows_and_summary(): void
    {
        $manager = $this->user(RoleCode::SUCURSAL_MANAGER);
        $fake = new FakeReportReadModelGateway;
        $this->app->instance(ReportReadModelGateway::class, $fake);
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/reports/CREDIT_LINE_SUMMARY?status=ACTIVE')
            ->assertOk()
            ->assertJsonPath('data.0.total', '100.00')
            ->assertJsonPath('meta.scope.type', 'BRANCH')
            ->assertJsonPath('meta.scope.branch_id', $manager->branch_public_id)
            ->assertJsonPath('meta.summary.total', '100.00')
            ->assertJsonPath('meta.timezone', 'America/Monterrey');
        $this->assertDatabaseHas('report_query_events', ['outcome' => 'ALLOWED', 'rows_returned' => 1]);
    }

    public function test_branch_filter_cannot_expand_scope_and_default_gateway_fails_closed(): void
    {
        $manager = $this->user(RoleCode::SUCURSAL_MANAGER);
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/reports/CREDIT_LINE_SUMMARY?branch_id='.Str::uuid())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'REPORT_SCOPE_DENIED');

        $this->getJson('/api/v1/reports/CREDIT_LINE_SUMMARY')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'REPORT_DEPENDENCY_UNAVAILABLE');
    }

    public function test_run_creation_is_idempotent_and_result_is_private_and_paged(): void
    {
        Queue::fake();
        $manager = $this->user(RoleCode::GENERAL_MANAGER);
        Sanctum::actingAs($manager);
        $headers = ['Idempotency-Key' => 'report-run-1', 'X-Request-Id' => (string) Str::uuid()];

        $first = $this->postJson('/api/v1/reports/CREDIT_LINE_SUMMARY/runs', ['status' => 'ACTIVE'], $headers)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'QUEUED');
        $second = $this->postJson('/api/v1/reports/CREDIT_LINE_SUMMARY/runs', ['status' => 'ACTIVE'], $headers)
            ->assertOk();
        self::assertSame($first->json('data.id'), $second->json('data.id'));
        self::assertSame(1, ReportRun::query()->count());
        Queue::assertPushed(ExecuteReportRunJob::class, 1);

        $fake = new FakeReportReadModelGateway;
        $this->app->instance(ReportReadModelGateway::class, $fake);
        (new ExecuteReportRunJob((string) $first->json('data.id')))->handle(
            app(ReportRegistry::class),
            $fake,
            app(ReportAuditRecorder::class),
            app(ReportResultStoreInterface::class),
            app(ReportResultProtector::class),
        );
        self::assertSame(ReportRunStatus::COMPLETED, ReportRun::query()->firstOrFail()->status);
        $this->getJson('/api/v1/report-runs/'.$first->json('data.id').'/results?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.distributor', 'Distribuidora ficticia')
            ->assertJsonPath('meta.pagination.total', 1);

        Sanctum::actingAs($this->user(RoleCode::GENERAL_MANAGER));
        $this->getJson('/api/v1/report-runs/'.$first->json('data.id'))->assertNotFound();
    }

    private function user(RoleCode $roleCode): User
    {
        $role = Role::query()->where('code', $roleCode->value)->firstOrFail();
        $branchId = $roleCode->isGlobal() ? null : Branch::query()->firstOrFail()->id;

        return User::factory()->create(['role_id' => $role->id, 'branch_id' => $branchId]);
    }
}
