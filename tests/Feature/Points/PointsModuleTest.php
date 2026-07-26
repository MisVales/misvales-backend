<?php

declare(strict_types=1);

namespace Tests\Feature\Points;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use App\Modules\Points\Application\DTOs\RelationLiquidationSnapshot;
use App\Modules\Points\Application\Services\EvaluateRelationPoints;
use App\Modules\Points\Application\Services\PointAccountService;
use App\Modules\Points\Domain\Enums\LiquidationClassification;
use App\Modules\Points\Domain\Enums\RelationPointEvaluationResult;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Points\Domain\ValueObjects\PointRuleSnapshot;
use App\Modules\Points\Infrastructure\Integrations\UnavailableRelationPointSource;
use App\Modules\Points\Infrastructure\Persistence\Models\PointAccountModel;
use App\Modules\Points\Infrastructure\Persistence\Models\PointLedgerEntryModel;
use App\Modules\Points\Infrastructure\Persistence\Models\RelationPointEvaluationModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PointsModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $distributor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\AccessFoundationSeeder']);
        $generalManagerRole = Role::query()->where('code', RoleCode::GENERAL_MANAGER->value)->firstOrFail();
        User::factory()->create([
            'role_id' => $generalManagerRole->id,
            'branch_id' => null,
            'state' => AccountState::ACTIVE,
        ]);
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\ConfigurationFoundationSeeder']);

        $branch = Branch::query()->firstOrFail();
        $role = Role::query()->where('code', RoleCode::DISTRIBUTOR->value)->firstOrFail();
        $this->distributor = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'state' => AccountState::ACTIVE,
        ]);
    }

    public function test_anticipated_liquidation_uses_m10_basis_and_is_idempotent(): void
    {
        $snapshot = $this->snapshot(LiquidationClassification::ANTICIPADA, '5000.0000');
        $first = app(EvaluateRelationPoints::class)->execute($snapshot);
        $second = app(EvaluateRelationPoints::class)->execute($snapshot);

        self::assertSame(RelationPointEvaluationResult::EARNED, $first->result);
        self::assertSame(12, $first->pointsEarned);
        self::assertTrue($second->alreadyProcessed);
        self::assertSame(1, RelationPointEvaluationModel::query()->count());
        self::assertSame(1, PointLedgerEntryModel::query()->count());
        self::assertSame(12, PointAccountModel::query()->value('total_points'));
    }

    public function test_punctual_liquidation_records_final_evaluation_without_movement(): void
    {
        $outcome = app(EvaluateRelationPoints::class)->execute(
            $this->snapshot(LiquidationClassification::PUNTUAL, '5000.0000'),
        );

        self::assertSame(RelationPointEvaluationResult::NO_CHANGE_PUNCTUAL, $outcome->result);
        self::assertSame(0, PointLedgerEntryModel::query()->count());
        self::assertSame(1, RelationPointEvaluationModel::query()->count());
    }

    public function test_partial_payment_waits_and_does_not_finalize_relation(): void
    {
        $snapshot = $this->snapshot(LiquidationClassification::ABONO, '5000.0000', false);
        $outcome = app(EvaluateRelationPoints::class)->execute($snapshot);

        self::assertSame(RelationPointEvaluationResult::WAITING_FOR_LIQUIDATION, $outcome->result);
        self::assertSame(0, RelationPointEvaluationModel::query()->count());
        self::assertSame(0, PointLedgerEntryModel::query()->count());
    }

    public function test_late_liquidation_penalizes_total_once(): void
    {
        $account = app(PointAccountService::class)->createForDistributor($this->distributor->id);
        $account->forceFill(['total_points' => 100, 'available_points' => 100])->save();
        $snapshot = $this->snapshot(LiquidationClassification::FUERA_DE_TIEMPO, '0.0000');

        $first = app(EvaluateRelationPoints::class)->execute($snapshot);
        $second = app(EvaluateRelationPoints::class)->execute($snapshot);

        self::assertSame(20, $first->pointsPenalized);
        self::assertTrue($second->alreadyProcessed);
        self::assertSame(80, $account->fresh()?->total_points);
        self::assertSame(-20, PointLedgerEntryModel::query()->value('signed_points'));
    }

    public function test_late_penalty_is_blocked_when_it_would_break_an_active_reservation(): void
    {
        $account = app(PointAccountService::class)->createForDistributor($this->distributor->id);
        $account->forceFill([
            'total_points' => 100,
            'reserved_points' => 90,
            'available_points' => 10,
        ])->save();

        $outcome = app(EvaluateRelationPoints::class)->execute(
            $this->snapshot(LiquidationClassification::FUERA_DE_TIEMPO, '0.0000'),
        );

        self::assertSame(RelationPointEvaluationResult::BLOCKED, $outcome->result);
        self::assertSame('POINT_RESERVATION_CONFLICT', $outcome->blockedCode);
        self::assertSame(100, $account->fresh()?->total_points);
        self::assertSame(0, RelationPointEvaluationModel::query()->count());
        self::assertSame(0, PointLedgerEntryModel::query()->count());
    }

    public function test_source_event_cannot_be_reused_for_another_relation(): void
    {
        $sourceEventId = (string) Str::uuid();
        app(EvaluateRelationPoints::class)->execute(
            $this->snapshot(LiquidationClassification::PUNTUAL, '0.0000', sourceEventId: $sourceEventId),
        );

        $this->expectException(PointsDomainException::class);
        $this->expectExceptionMessage('otra relación');
        app(EvaluateRelationPoints::class)->execute(
            $this->snapshot(LiquidationClassification::PUNTUAL, '0.0000', sourceEventId: $sourceEventId),
        );
    }

    public function test_reconciliation_detects_difference_without_correcting_it(): void
    {
        $account = app(PointAccountService::class)->createForDistributor($this->distributor->id);
        $account->forceFill(['total_points' => 10, 'available_points' => 10])->save();

        $result = app(PointAccountService::class)->reconcile($account->id);

        self::assertFalse($result['consistent']);
        self::assertSame(10, $account->fresh()?->total_points);
        $this->assertDatabaseHas('point_audit_events', ['event_type' => 'POINT_ACCOUNT_INCONSISTENCY_DETECTED']);
    }

    public function test_own_balance_is_visible_and_redemption_mutations_are_not_published(): void
    {
        $this->actingAs($this->distributor)
            ->getJson('/api/v1/me/points')
            ->assertOk()
            ->assertJsonPath('data.total_points', 0)
            ->assertJsonPath('data.redemption_open', false);

        $requestResponse = $this->actingAs($this->distributor)
            ->postJson('/api/v1/me/point-redemptions', ['requested_points' => 10]);
        self::assertContains($requestResponse->status(), [404, 405]);

        $completeResponse = $this->actingAs($this->distributor)
            ->postJson('/api/v1/point-redemptions/'.Str::uuid().'/complete', []);
        self::assertContains($completeResponse->status(), [404, 405]);
    }

    public function test_distributor_ownership_and_branch_manager_scope_are_enforced(): void
    {
        $role = Role::query()->where('code', RoleCode::DISTRIBUTOR->value)->firstOrFail();
        $other = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $this->distributor->branch_id,
            'state' => AccountState::ACTIVE,
        ]);
        $managerRole = Role::query()->where('code', RoleCode::SUCURSAL_MANAGER->value)->firstOrFail();
        $manager = User::factory()->create([
            'role_id' => $managerRole->id,
            'branch_id' => $this->distributor->branch_id,
            'state' => AccountState::ACTIVE,
        ]);

        $this->actingAs($other)
            ->getJson('/api/v1/distributors/'.$this->distributor->public_id.'/points')
            ->assertNotFound();
        $this->actingAs($manager)
            ->getJson('/api/v1/distributors/'.$this->distributor->public_id.'/points')
            ->assertOk();
        $this->actingAs($manager)
            ->getJson('/api/v1/distributors/not-a-uuid/points')
            ->assertNotFound();
    }

    public function test_database_constraint_rejects_reserved_points_above_total(): void
    {
        $account = app(PointAccountService::class)->createForDistributor($this->distributor->id);
        $this->expectException(QueryException::class);

        DB::table('point_accounts')->where('id', $account->id)->update([
            'total_points' => 1,
            'reserved_points' => 2,
            'available_points' => -1,
        ]);
    }

    public function test_relation_source_is_fail_closed_until_m10_integration_exists(): void
    {
        $this->expectException(PointsDomainException::class);
        $this->expectExceptionMessage('M10');

        (new UnavailableRelationPointSource)->findEligible((string) Str::uuid());
    }

    private function snapshot(
        LiquidationClassification $classification,
        string $basis,
        bool $liquidated = true,
        ?string $sourceEventId = null,
    ): RelationLiquidationSnapshot {
        $effective = CarbonImmutable::parse('2026-07-10 12:00:00', 'America/Monterrey');

        return new RelationLiquidationSnapshot(
            relationId: (string) Str::uuid(),
            distributorId: $this->distributor->id,
            branchId: (int) $this->distributor->branch_id,
            classification: $classification,
            effectiveLiquidationAt: $effective,
            financialStateVersion: '7',
            sourceEventId: $sourceEventId ?? (string) Str::uuid(),
            isLiquidated: $liquidated,
            productsCapitalBasis: $basis,
            ruleSnapshot: new PointRuleSnapshot(
                divisorVersionId: (string) Str::uuid(),
                divisor: '1200.0000',
                multiplierVersionId: (string) Str::uuid(),
                multiplier: 3,
                penaltyRateVersionId: (string) Str::uuid(),
                penaltyRate: '0.2000',
            ),
            earlyPaymentStartsAt: $effective->subDays(10),
            earlyPaymentEndsAt: $effective->addDays(10),
        );
    }
}
