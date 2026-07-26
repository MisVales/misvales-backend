<?php

declare(strict_types=1);

namespace Tests\Feature\RiskDelinquency;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use App\Modules\RiskDelinquency\Application\Contracts\CanDistributorIssueVoucher;
use App\Modules\RiskDelinquency\Application\Contracts\OverdueBalancePort;
use App\Modules\RiskDelinquency\Application\DTOs\RelationPostDueEvaluation;
use App\Modules\RiskDelinquency\Application\Services\ApplyDistributorDelinquency;
use App\Modules\RiskDelinquency\Application\Services\ConsumeRelationPostDueEvaluation;
use App\Modules\RiskDelinquency\Application\Services\DecideDelinquencyRemoval;
use App\Modules\RiskDelinquency\Application\Services\DetectFinancialRegularization;
use App\Modules\RiskDelinquency\Application\Services\PrepareDelinquencyRemoval;
use App\Modules\RiskDelinquency\Domain\Enums\DelinquencyStatus;
use App\Modules\RiskDelinquency\Domain\Enums\FinancialResult;
use App\Modules\RiskDelinquency\Domain\Enums\RelationRiskEvaluationStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RemovalRequestStatus;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\RiskDelinquency\Infrastructure\Integrations\UnavailableRelationRiskSource;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DelinquencyDecision;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DelinquencyRemovalRequest;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RelationRiskEvaluation;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RiskDelinquencyModuleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $coordinator;

    private User $distributor;

    private MutableOverdueBalance $balance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessFoundationSeeder::class);
        $this->branch = Branch::query()->firstOrFail();
        $this->coordinator = $this->user(RoleCode::COORDINATOR, $this->branch);
        $this->distributor = $this->user(RoleCode::DISTRIBUTOR, $this->branch, [
            'coordinator_id' => $this->coordinator->id,
        ]);
        $this->balance = new MutableOverdueBalance('0.0000');
        $this->app->instance(OverdueBalancePort::class, $this->balance);
    }

    public function test_first_second_and_third_breaches_create_one_ordered_sequence_and_alert_evidence(): void
    {
        $evaluations = $this->consumeThreeBreaches();

        self::assertSame(3, DistributorRiskProfile::query()->value('consecutive_breaches'));
        self::assertSame(3, RiskAlert::query()->count());
        self::assertSame(3, RiskAlert::query()->where('alert_type', 'THIRD_BREACH')->firstOrFail()->relations()->count());
        self::assertSame(3, RelationRiskEvaluation::query()->count());
        self::assertSame(
            array_map(fn (RelationPostDueEvaluation $item): string => $item->relationId, $evaluations),
            DB::table('risk_sequence_relations')->orderBy('position')->pluck('relation_id')->all(),
        );
        $this->assertDatabaseHas('outbox_events', ['type' => 'ThirdConsecutiveRelationBreachDetected']);
    }

    public function test_repeated_source_version_does_not_count_or_emit_twice(): void
    {
        $input = $this->evaluation(1, FinancialResult::NO_PAGO, '100.0000');
        $first = app(ConsumeRelationPostDueEvaluation::class)->consume($input);
        $second = app(ConsumeRelationPostDueEvaluation::class)->consume($input);

        self::assertTrue($first->is($second));
        self::assertSame(1, RelationRiskEvaluation::query()->count());
        self::assertSame(1, DistributorRiskProfile::query()->value('consecutive_breaches'));
        self::assertSame(1, RiskAlert::query()->count());
    }

    public function test_pending_source_neither_complies_nor_breaches(): void
    {
        $pending = $this->evaluation(1, FinancialResult::NO_PAGO, '100.0000', false);
        $result = app(ConsumeRelationPostDueEvaluation::class)->consume($pending);

        self::assertSame(RelationRiskEvaluationStatus::PENDING_SOURCE, $result->evaluation_status);
        self::assertSame(0, DistributorRiskProfile::query()->value('consecutive_breaches'));
        self::assertSame(0, RiskAlert::query()->count());
    }

    public function test_compliant_relation_breaks_sequence_but_does_not_delete_history(): void
    {
        app(ConsumeRelationPostDueEvaluation::class)->consume($this->evaluation(1, FinancialResult::NO_PAGO, '100.0000'));
        app(ConsumeRelationPostDueEvaluation::class)->consume($this->evaluation(2, FinancialResult::LIQUIDO, '0.0000'));

        self::assertSame(0, DistributorRiskProfile::query()->value('consecutive_breaches'));
        self::assertSame(2, RelationRiskEvaluation::query()->count());
        $this->assertDatabaseHas('risk_sequences', ['status' => 'RESET_BY_COMPLIANCE']);
    }

    public function test_corrected_source_version_rebuilds_the_affected_sequence_and_preserves_history(): void
    {
        $evaluations = $this->consumeThreeBreaches();
        $original = $evaluations[1];
        $correction = new RelationPostDueEvaluation(
            relationId: $original->relationId,
            distributorId: $original->distributorId,
            branchId: $original->branchId,
            cutId: $original->cutId,
            cutAt: $original->cutAt,
            dueAt: $original->dueAt,
            result: FinancialResult::LIQUIDO,
            overdueBalance: '0.0000',
            evaluatedAt: $original->evaluatedAt->addMinute(),
            sourceVersion: 'v2-corrected',
            sourceReady: true,
        );

        app(ConsumeRelationPostDueEvaluation::class)->consume($correction);

        self::assertSame(4, RelationRiskEvaluation::query()->count());
        self::assertSame(1, DistributorRiskProfile::query()->value('consecutive_breaches'));
        self::assertSame(3, RiskAlert::query()->where('status', 'SUPERSEDED')->count());
        self::assertSame(1, RiskAlert::query()->where('status', 'ACTIVE')->count());
        $this->assertDatabaseHas('risk_sequences', ['status' => 'SUPERSEDED']);
    }

    public function test_application_is_manual_reauthenticated_idempotent_and_blocks_issuance(): void
    {
        $this->consumeThreeBreaches();
        $manager = $this->user(RoleCode::GENERAL_MANAGER);
        $alert = RiskAlert::query()->where('alert_type', 'THIRD_BREACH')->firstOrFail();
        $token = $this->reauthorize(
            $manager,
            CriticalAction::DELINQUENCY_APPLY,
            RiskAlert::class,
            $alert->alert_number,
            [],
        );
        $service = app(ApplyDistributorDelinquency::class);
        $first = $service->apply($manager, $alert->alert_number, $token, 'apply-key', null);
        $second = $service->apply($manager, $alert->alert_number, 'spent-token', 'apply-key', null);

        self::assertTrue($first->is($second));
        self::assertSame(1, DelinquencyDecision::query()->count());
        self::assertSame(DelinquencyStatus::DELINQUENT, DistributorRiskProfile::query()->firstOrFail()->delinquency_status);
        self::assertFalse(app(CanDistributorIssueVoucher::class)->check($this->distributor->id)->allowed);
    }

    public function test_regularization_preserves_block_until_coordinator_prepares_and_manager_approves(): void
    {
        $this->consumeThreeBreaches();
        $manager = $this->user(RoleCode::GENERAL_MANAGER);
        $alert = RiskAlert::query()->where('alert_type', 'THIRD_BREACH')->firstOrFail();
        $applyToken = $this->reauthorize($manager, CriticalAction::DELINQUENCY_APPLY, RiskAlert::class, $alert->alert_number, []);
        app(ApplyDistributorDelinquency::class)->apply($manager, $alert->alert_number, $applyToken, 'apply-before-removal', null);

        $this->balance->value = '0.0000';
        $profile = app(DetectFinancialRegularization::class)->detect($this->distributor->id, 'payment-v9');
        self::assertSame(DelinquencyStatus::REGULARIZED_PENDING_REMOVAL, $profile->delinquency_status);
        self::assertTrue($profile->blocked_for_new_vouchers);

        $request = app(PrepareDelinquencyRemoval::class)->prepare(
            $this->coordinator,
            $this->distributor,
            'prepare-key',
            null,
        );
        $approveToken = $this->reauthorize(
            $manager,
            CriticalAction::DELINQUENCY_REMOVE,
            DelinquencyRemovalRequest::class,
            $request->request_number,
            ['decision' => RemovalRequestStatus::APPROVED->value],
        );
        $approved = app(DecideDelinquencyRemoval::class)->approve(
            $manager,
            $request->request_number,
            $approveToken,
            'approve-key',
            null,
        );

        self::assertSame(RemovalRequestStatus::APPROVED, $approved->status);
        self::assertSame(DelinquencyStatus::NOT_DELINQUENT, $profile->fresh()?->delinquency_status);
        self::assertTrue(app(CanDistributorIssueVoucher::class)->check($this->distributor->id)->allowed);
    }

    public function test_new_overdue_balance_invalidates_prepared_request_without_unblocking(): void
    {
        $this->regularizedWithPreparedRequest();
        $this->balance->value = '1.0000';

        $profile = app(DetectFinancialRegularization::class)->detect($this->distributor->id, 'reversal-v1');

        self::assertTrue($profile->blocked_for_new_vouchers);
        $this->assertDatabaseHas('delinquency_removal_requests', ['status' => 'INVALIDATED']);
    }

    public function test_distributor_sees_only_own_profile_and_verifier_has_no_access(): void
    {
        app(ConsumeRelationPostDueEvaluation::class)->consume($this->evaluation(1, FinancialResult::NO_PAGO, '10.0000'));
        $other = $this->user(RoleCode::DISTRIBUTOR, $this->branch);
        $verifier = $this->user(RoleCode::VERIFIER, $this->branch);
        $profile = DistributorRiskProfile::query()->firstOrFail();

        self::assertTrue(Gate::forUser($this->distributor)->allows('view', $profile));
        self::assertFalse(Gate::forUser($other)->allows('view', $profile));

        $this->actingAs($this->distributor)
            ->getJson('/api/v1/risk/distributors/'.$this->distributor->public_id)
            ->assertOk()
            ->assertJsonPath('data.consecutive_breaches', 1);
        $this->actingAs($this->distributor)
            ->getJson('/api/v1/risk/distributors/'.$this->distributor->public_id.'/alerts')
            ->assertNotFound();
        $this->actingAs($other)
            ->getJson('/api/v1/risk/distributors/'.$this->distributor->public_id)
            ->assertNotFound();
        $this->actingAs($verifier)
            ->getJson('/api/v1/risk/distributors/'.$this->distributor->public_id)
            ->assertNotFound();
    }

    public function test_database_rejects_inconsistent_delinquency_block(): void
    {
        app(ConsumeRelationPostDueEvaluation::class)->consume($this->evaluation(1, FinancialResult::NO_PAGO, '10.0000'));
        $profile = DistributorRiskProfile::query()->firstOrFail();
        $this->expectException(QueryException::class);

        DB::table('distributor_risk_profiles')->where('id', $profile->id)->update([
            'delinquency_status' => 'DELINQUENT',
            'blocked_for_new_vouchers' => false,
        ]);
    }

    public function test_source_contract_fails_closed_and_has_no_client_subject(): void
    {
        $this->expectException(RiskDelinquencyException::class);
        try {
            (new UnavailableRelationRiskSource)->definitiveEvaluation((string) Str::uuid());
        } finally {
            self::assertFalse(Schema::hasColumn('distributor_risk_profiles', 'client_id'));
            self::assertFalse(Schema::hasColumn('relation_risk_evaluations', 'client_id'));
        }
    }

    public function test_access_notification_dispatcher_does_not_consume_m17_domain_outbox(): void
    {
        app(ConsumeRelationPostDueEvaluation::class)->consume(
            $this->evaluation(1, FinancialResult::NO_PAGO, '10.0000'),
        );
        Queue::fake();

        $this->artisan('access:outbox-dispatch')->assertSuccessful();

        $this->assertDatabaseHas('outbox_events', [
            'type' => 'FirstRelationBreachDetected',
            'state' => 'PENDING',
        ]);
    }

    /** @return list<RelationPostDueEvaluation> */
    private function consumeThreeBreaches(): array
    {
        $evaluations = [
            $this->evaluation(1, FinancialResult::NO_PAGO, '100.0000'),
            $this->evaluation(2, FinancialResult::ABONO, '75.0000'),
            $this->evaluation(3, FinancialResult::NO_PAGO, '50.0000'),
        ];
        foreach ($evaluations as $evaluation) {
            app(ConsumeRelationPostDueEvaluation::class)->consume($evaluation);
        }

        return $evaluations;
    }

    private function regularizedWithPreparedRequest(): void
    {
        $this->consumeThreeBreaches();
        $manager = $this->user(RoleCode::GENERAL_MANAGER);
        $alert = RiskAlert::query()->where('alert_type', 'THIRD_BREACH')->firstOrFail();
        $token = $this->reauthorize($manager, CriticalAction::DELINQUENCY_APPLY, RiskAlert::class, $alert->alert_number, []);
        app(ApplyDistributorDelinquency::class)->apply($manager, $alert->alert_number, $token, 'apply-invalidated', null);
        $this->balance->value = '0.0000';
        app(DetectFinancialRegularization::class)->detect($this->distributor->id, 'regularized-v1');
        app(PrepareDelinquencyRemoval::class)->prepare($this->coordinator, $this->distributor, 'prepare-invalidated', null);
    }

    private function evaluation(
        int $position,
        FinancialResult $result,
        string $balance,
        bool $ready = true,
    ): RelationPostDueEvaluation {
        $due = CarbonImmutable::parse("2026-06-0{$position} 08:00:00", 'America/Monterrey');

        return new RelationPostDueEvaluation(
            relationId: (string) Str::uuid(),
            distributorId: $this->distributor->id,
            branchId: (int) $this->distributor->branch_id,
            cutId: 'CUT-'.$position,
            cutAt: $due->subDays(7),
            dueAt: $due,
            result: $result,
            overdueBalance: $balance,
            evaluatedAt: $due->addDay()->setTime(8, 30)->utc(),
            sourceVersion: 'v'.$position,
            sourceReady: $ready,
        );
    }

    /** @param array<string, mixed> $extra */
    private function user(RoleCode $role, ?Branch $branch = null, array $extra = []): User
    {
        $roleModel = Role::query()->where('code', $role->value)->firstOrFail();

        return User::factory()->create([
            'role_id' => $roleModel->id,
            'branch_id' => $role->isGlobal() ? null : ($branch ?? $this->branch)->id,
            'state' => AccountState::ACTIVE,
            ...$extra,
        ]);
    }

    /** @param array<string, mixed> $parameters */
    private function reauthorize(
        User $actor,
        CriticalAction $action,
        string $resourceType,
        string $resourceId,
        array $parameters,
    ): string {
        $session = AuthSession::query()->create([
            'user_id' => $actor->id,
            'application' => 'administrativa',
            'device_id' => 'risk-test',
            'ip_address' => '127.0.0.1',
            'context_version' => $actor->context_version,
            'last_activity_at' => now('UTC'),
            'expires_at' => now('UTC')->addHour(),
            'state' => 'ACTIVE',
        ]);
        $created = $actor->createToken('risk-tests', ['*'], now('UTC')->addHour());
        $created->accessToken->forceFill([
            'auth_session_id' => $session->id,
            'context_version' => $actor->context_version,
        ])->save();
        $actor->withAccessToken($created->accessToken);
        $plain = Str::random(64);
        $binding = new AuthorizationBinding(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            branchId: $actor->branch_public_id,
            parameters: $parameters,
        );
        ReauthAuthorization::query()->create([
            'user_id' => $actor->id,
            'auth_session_id' => $session->id,
            'requester_user_id' => $actor->id,
            'method' => 'PASSWORD_TOTP',
            'action' => $action->value,
            'resource_type' => $resourceType,
            'record_id' => $resourceId,
            'branch_id' => $actor->branch_public_id,
            'parameters_hash' => $binding->parametersHash(),
            'context_version' => $actor->context_version,
            'token_hash' => hash('sha256', $plain),
            'issued_at' => now('UTC'),
            'expires_at' => now('UTC')->addMinutes(5),
        ]);

        return $plain;
    }
}

final class MutableOverdueBalance implements OverdueBalancePort
{
    public function __construct(public string $value) {}

    public function totalForDistributor(int $distributorId): string
    {
        return $this->value;
    }
}
