<?php

declare(strict_types=1);

namespace Tests\Feature\Credit;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\DistributorAccessLink;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use App\Modules\Credit\Application\DTOs\CapitalRecovery;
use App\Modules\Credit\Application\DTOs\InitialCreditAuthorization;
use App\Modules\Credit\Application\DTOs\VoucherCapitalUsage;
use App\Modules\Credit\Application\Services\CreditIncreaseService;
use App\Modules\Credit\Application\Services\CreditLedgerReconstructor;
use App\Modules\Credit\Application\Services\CreditLineOperationsService;
use App\Modules\Credit\Application\Services\CreditQueryService;
use App\Modules\Credit\Application\Services\InitialCreditLineService;
use App\Modules\Credit\Domain\Enums\IncreaseOriginType;
use App\Modules\Credit\Domain\Enums\IncreaseRequestStatus;
use App\Modules\Credit\Domain\Enums\RestrictionStatus;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditUsageRestrictionModel;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreditModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_initial_authorization_creates_one_line_movement_restriction_audit_and_events_atomically(): void
    {
        [$distributor, , $manager] = $this->creditContext();
        $line = $this->registerLine($distributor, $manager, '20000');

        self::assertSame('20000.0000', $line->total_authorized);
        self::assertSame('0.0000', $line->used_balance);
        self::assertSame('20000.0000', $line->available_balance);
        self::assertDatabaseCount('credit_lines', 1);
        self::assertDatabaseHas('credit_line_movements', [
            'credit_line_id' => $line->id,
            'type' => 'INITIAL_AUTHORIZATION',
        ]);
        self::assertDatabaseHas('credit_usage_restrictions', [
            'credit_line_id' => $line->id,
            'status' => 'ACTIVE',
            'reference_amount' => '10000.0000',
            'tolerance_amount' => '500.0000',
        ]);
        self::assertDatabaseHas('credit_audit_events', ['event_type' => 'CREDIT_LINE_INITIAL_AUTHORIZED']);
        self::assertDatabaseHas('outbox_events', ['type' => 'CreditLineInitiallyAuthorized']);

        $same = $this->registerLine($distributor, $manager, '20000');
        self::assertSame($line->id, $same->id);
        self::assertDatabaseCount('credit_line_movements', 1);
    }

    public function test_restriction_binding_release_fulfillment_and_recovery_keep_ledger_consistent(): void
    {
        [$distributor, , $manager] = $this->creditContext();
        $line = $this->registerLine($distributor, $manager, '20000');
        $operations = $this->app->make(CreditLineOperationsService::class);

        $operations->bindRestriction($distributor->id, 'voucher-1', new Money('9500'), $distributor->id);
        self::assertDatabaseHas('credit_usage_restrictions', ['bound_voucher_id' => 'voucher-1', 'status' => 'BOUND']);
        $operations->releaseRestriction($distributor->id, 'voucher-1', $distributor->id);
        self::assertDatabaseHas('credit_usage_restrictions', ['bound_voucher_id' => null, 'status' => 'ACTIVE']);

        $usage = new VoucherCapitalUsage(
            $distributor->id,
            'voucher-2',
            new Money('10500'),
            $distributor->id,
            $distributor->branch_id,
            'Vale feriado.',
            'voucher-fulfilled-2',
        );
        $operations->bindRestriction($distributor->id, 'voucher-2', $usage->capital, $distributor->id);
        $operations->applyFulfilledVoucher($usage);
        $operations->applyFulfilledVoucher($usage);

        $line->refresh();
        self::assertSame('10500.0000', $line->used_balance);
        self::assertSame('9500.0000', $line->available_balance);
        self::assertSame(RestrictionStatus::CONSUMED, CreditUsageRestrictionModel::query()->sole()->status);
        self::assertDatabaseCount('credit_line_movements', 2);

        $applied = $operations->recover(new CapitalRecovery(
            $distributor->id,
            'payment-allocation-1',
            new Money('20000'),
            $manager->id,
            $distributor->branch_id,
            'Pago conciliado.',
            'capital-recovery-1',
            true,
            $manager->id,
        ));
        self::assertSame('10500.00', $applied->format());
        self::assertSame('0.0000', $line->refresh()->used_balance);
        self::assertSame('10500.0000', $line->recovered_capital_total);
        self::assertDatabaseCount('credit_usage_restrictions', 1);
        self::assertSame(RestrictionStatus::CONSUMED, CreditUsageRestrictionModel::query()->sole()->status);
        $this->app->make(CreditLedgerReconstructor::class)->assertMatches($line->refresh());
    }

    public function test_increase_flow_supports_backend_difference_preauthorization_and_partial_authorization(): void
    {
        [$distributor, $coordinator, $manager, $branch] = $this->creditContext();
        $line = $this->registerLine($distributor, $manager, '20000');
        $service = $this->app->make(CreditIncreaseService::class);
        $request = $service->request(
            $distributor,
            $distributor,
            new Money('7000'),
            'Se requiere capacidad adicional.',
            IncreaseOriginType::INSUFFICIENT_CREDIT,
            new Money('25000'),
            'increase-request-one',
        );
        self::assertSame('5000.0000', $request->required_difference);
        self::assertSame(IncreaseRequestStatus::REQUESTED, $request->status);

        $request = $service->review(
            $coordinator,
            $request,
            'PREAUTHORIZE',
            new Money('6500'),
            'Historial revisado y favorable.',
            $request->lock_version,
        );
        self::assertSame(IncreaseRequestStatus::PREAUTHORIZED, $request->status);

        [$manager, $reauthToken] = $this->grantManagerReauthentication(
            $manager,
            $request->public_id,
            $branch,
            ['decision' => 'AUTHORIZE', 'authorized_amount' => '6000.00'],
        );
        $request = $service->managerDecision(
            $manager,
            $request,
            'AUTHORIZE',
            new Money('6000'),
            'Se autoriza un importe menor.',
            $reauthToken,
            $request->lock_version,
        );
        self::assertSame(IncreaseRequestStatus::FIFTY_PERCENT_RESTRICTION_ACTIVE, $request->status);
        self::assertSame('6000.0000', $request->authorized_amount);
        self::assertSame('26000.0000', $line->refresh()->total_authorized);
        self::assertDatabaseHas('credit_line_movements', ['type' => 'INCREASE', 'total_after' => '26000.0000']);
        self::assertDatabaseHas('credit_usage_restrictions', [
            'trigger_type' => 'INCREASE',
            'base_total_authorized' => '26000.0000',
            'reference_amount' => '13000.0000',
        ]);
        self::assertDatabaseHas('outbox_events', ['type' => 'CreditIncreasePartiallyAuthorized']);
        $this->app->make(CreditLedgerReconstructor::class)->assertMatches($line->refresh());
    }

    public function test_coordinator_manager_and_scope_rules_reject_invalid_transitions_and_actors(): void
    {
        [$distributor, , $manager] = $this->creditContext();
        $this->registerLine($distributor, $manager, '20000');
        $request = $this->app->make(CreditIncreaseService::class)->request(
            $distributor,
            $distributor,
            new Money('5000'),
            'Se requiere capacidad adicional.',
            IncreaseOriginType::NORMAL,
            null,
            'increase-request-scope',
        );
        $otherCoordinator = User::factory()->coordinator()->create(['state' => AccountState::ACTIVE]);

        $this->expectException(CreditRuleViolation::class);
        $this->expectExceptionMessage('alcance');
        $this->app->make(CreditIncreaseService::class)->review(
            $otherCoordinator,
            $request,
            'REJECT',
            null,
            'No corresponde a su asignación.',
        );
    }

    public function test_coordinator_and_manager_rejections_do_not_change_line_and_full_authorization_does(): void
    {
        [$distributor, $coordinator, $manager, $branch] = $this->creditContext();
        $line = $this->registerLine($distributor, $manager, '20000');
        $service = $this->app->make(CreditIncreaseService::class);

        $coordinatorRejected = $service->request(
            $distributor,
            $distributor,
            new Money('4000'),
            'Primera solicitud de prueba.',
            IncreaseOriginType::NORMAL,
            null,
            'coordinator-rejection',
        );
        $coordinatorRejected = $service->review(
            $coordinator,
            $coordinatorRejected,
            'REJECT',
            null,
            'No se preautoriza por el momento.',
        );
        self::assertSame(IncreaseRequestStatus::REJECTED_BY_COORDINATOR, $coordinatorRejected->status);
        self::assertSame('20000.0000', $line->refresh()->total_authorized);
        self::assertDatabaseCount('credit_usage_restrictions', 1);

        $managerRejected = $service->request(
            $distributor,
            $distributor,
            new Money('4500'),
            'Segunda solicitud de prueba.',
            IncreaseOriginType::NORMAL,
            null,
            'manager-rejection',
        );
        $managerRejected = $service->review(
            $coordinator,
            $managerRejected,
            'PREAUTHORIZE',
            new Money('4000'),
            'Se remite a decisión gerencial.',
        );
        [$manager, $rejectToken] = $this->grantManagerReauthentication(
            $manager,
            $managerRejected->public_id,
            $branch,
            ['decision' => 'REJECT', 'authorized_amount' => null],
        );
        $managerRejected = $service->managerDecision(
            $manager,
            $managerRejected,
            'REJECT',
            null,
            'Incremento no autorizado.',
            $rejectToken,
        );
        self::assertSame(IncreaseRequestStatus::REJECTED_BY_MANAGER, $managerRejected->status);
        self::assertSame('20000.0000', $line->refresh()->total_authorized);
        self::assertDatabaseCount('credit_usage_restrictions', 1);

        $fullyAuthorized = $service->request(
            $distributor,
            $distributor,
            new Money('5000'),
            'Tercera solicitud de prueba.',
            IncreaseOriginType::NORMAL,
            null,
            'full-authorization',
        );
        $fullyAuthorized = $service->review(
            $coordinator,
            $fullyAuthorized,
            'PREAUTHORIZE',
            new Money('5000'),
            'Se recomienda el importe completo.',
        );
        [$manager, $authorizeToken] = $this->grantManagerReauthentication(
            $manager,
            $fullyAuthorized->public_id,
            $branch,
            ['decision' => 'AUTHORIZE', 'authorized_amount' => '5000.00'],
        );
        $fullyAuthorized = $service->managerDecision(
            $manager,
            $fullyAuthorized,
            'AUTHORIZE',
            new Money('5000'),
            'Incremento completo autorizado.',
            $authorizeToken,
        );
        self::assertSame(IncreaseRequestStatus::FIFTY_PERCENT_RESTRICTION_ACTIVE, $fullyAuthorized->status);
        self::assertSame('25000.0000', $line->refresh()->total_authorized);
        self::assertDatabaseCount('credit_usage_restrictions', 2);
        self::assertDatabaseHas('outbox_events', ['type' => 'CreditIncreaseFullyAuthorized']);
    }

    public function test_read_scope_covers_global_branch_assignment_admin_and_other_roles(): void
    {
        [$distributor, $coordinator, $generalManager, $branch] = $this->creditContext();
        $this->registerLine($distributor, $generalManager, '20000');
        [$otherDistributor, , $otherGeneralManager] = $this->creditContext();
        $this->registerLine($otherDistributor, $otherGeneralManager, '10000');
        $queries = $this->app->make(CreditQueryService::class);
        $admin = User::factory()->administrator()->create(['state' => AccountState::ACTIVE]);
        $branchManager = User::factory()->sucursalManager()->create([
            'branch_id' => $branch->id,
            'state' => AccountState::ACTIVE,
        ]);
        $cashier = User::factory()->cashier()->create([
            'branch_id' => $branch->id,
            'state' => AccountState::ACTIVE,
        ]);

        self::assertSame('20000.00', $queries->summary($generalManager, $distributor)['total_authorized']);
        self::assertSame('10000.00', $queries->summary($admin, $otherDistributor)['total_authorized']);
        self::assertSame('20000.00', $queries->summary($branchManager, $distributor)['total_authorized']);
        self::assertSame('20000.00', $queries->summary($coordinator, $distributor)['total_authorized']);
        self::assertSame('20000.00', $queries->summary($distributor, $distributor)['total_authorized']);

        $this->assertScopeDenied(fn () => $queries->summary($branchManager, $otherDistributor));
        $this->assertScopeDenied(fn () => $queries->summary($coordinator, $otherDistributor));
        $this->assertScopeDenied(fn () => $queries->summary($distributor, $otherDistributor));
        $this->assertScopeDenied(fn () => $queries->summary($cashier, $distributor));
    }

    public function test_database_prevents_duplicate_line_and_invalid_materialized_balances(): void
    {
        [$distributor, , $manager] = $this->creditContext();
        $line = $this->registerLine($distributor, $manager, '20000');
        $this->expectException(QueryException::class);
        CreditLineModel::query()->create([
            'distributor_id' => $distributor->id,
            'total_authorized' => '100.0000',
            'used_balance' => '101.0000',
            'available_balance' => '-1.0000',
            'recovered_capital_total' => '0.0000',
            'lock_version' => 1,
        ]);
        self::assertSame('20000.0000', $line->refresh()->total_authorized);
    }

    public function test_api_enforces_ownership_and_returns_decimal_strings(): void
    {
        [$distributor, , $manager] = $this->creditContext();
        $this->registerLine($distributor, $manager, '20000');
        [$otherDistributor, , $otherManager] = $this->creditContext();
        $this->registerLine($otherDistributor, $otherManager, '10000');
        $accessToken = $this->accessToken($distributor, 'distribuidora');

        $this->withToken($accessToken)
            ->getJson("/api/v1/distributors/{$distributor->public_id}/credit-line")
            ->assertOk()
            ->assertJsonPath('data.total_authorized', '20000.00')
            ->assertJsonPath('data.restriction.lower_limit', '9500.00');

        $this->withToken($accessToken)
            ->getJson("/api/v1/distributors/{$otherDistributor->public_id}/credit-line")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');

        $this->withToken($accessToken)
            ->withHeader('Idempotency-Key', 'api-increase-request-1')
            ->postJson("/api/v1/distributors/{$distributor->public_id}/credit-increase-requests", [
                'requested_amount' => '5000.00',
                'reason' => 'Incremento solicitado para continuar operaciones.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'SOLICITADO')
            ->assertJsonPath('data.requested_amount', '5000.00');

        $this->withToken($accessToken)
            ->withHeader('Idempotency-Key', 'api-increase-request-other')
            ->postJson("/api/v1/distributors/{$otherDistributor->public_id}/credit-increase-requests", [
                'requested_amount' => '5000.00',
                'reason' => 'Intento sobre una cuenta ajena.',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
    }

    public function test_competing_vouchers_cannot_claim_one_restriction_or_overspend_the_line(): void
    {
        [$distributor, , $manager] = $this->creditContext();
        $this->registerLine($distributor, $manager, '20000');
        $operations = $this->app->make(CreditLineOperationsService::class);
        $operations->bindRestriction($distributor->id, 'voucher-first', new Money('10000'), $distributor->id);

        try {
            $operations->bindRestriction($distributor->id, 'voucher-second', new Money('10000'), $distributor->id);
            self::fail('Una segunda operación no debe reclamar la restricción bloqueada.');
        } catch (CreditRuleViolation $exception) {
            self::assertSame('CREDIT_RESTRICTION_ALREADY_BOUND', $exception->errorCode());
        }

        $operations->applyFulfilledVoucher(new VoucherCapitalUsage(
            $distributor->id,
            'voucher-first',
            new Money('10000'),
            $distributor->id,
            $distributor->branch_id,
            'Primer vale feriado.',
            'competing-voucher-first',
        ));
        $this->expectException(CreditRuleViolation::class);
        $this->expectExceptionMessage('saldo disponible');
        $operations->applyFulfilledVoucher(new VoucherCapitalUsage(
            $distributor->id,
            'voucher-too-large',
            new Money('10000.01'),
            $distributor->id,
            $distributor->branch_id,
            'Segundo vale concurrente.',
            'competing-voucher-second',
        ));
    }

    /** @return array{User, User, User, Branch} */
    private function creditContext(): array
    {
        $branch = Branch::factory()->create();
        $coordinator = User::factory()->coordinator()->create([
            'branch_id' => $branch->id,
            'state' => AccountState::ACTIVE,
        ]);
        $manager = User::factory()->generalManager()->create(['state' => AccountState::ACTIVE]);
        $distributor = User::factory()->distributor()->create([
            'branch_id' => $branch->id,
            'coordinator_id' => $coordinator->id,
            'state' => AccountState::ACTIVE,
        ]);
        DistributorAccessLink::query()->create([
            'user_id' => $distributor->id,
            'external_request_id' => 'authorization-'.$distributor->id,
            'external_distributor_id' => 'distributor-'.$distributor->id,
            'branch_id' => $branch->id,
            'coordinator_user_id' => $coordinator->id,
            'authorized_by' => $manager->id,
            'initial_credit_line' => '20000.00',
            'authorized_at' => now('UTC'),
        ]);

        return [$distributor, $coordinator, $manager, $branch];
    }

    private function registerLine(User $distributor, User $manager, string $amount): CreditLineModel
    {
        return $this->app->make(InitialCreditLineService::class)->register(new InitialCreditAuthorization(
            $distributor->id,
            new Money($amount),
            $manager->id,
            $distributor->branch_id,
            'Autorización final validada.',
            'authorization-'.$distributor->id,
            'initial-line-'.$distributor->id,
        ));
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{User, string}
     */
    private function grantManagerReauthentication(User $manager, string $requestId, Branch $branch, array $parameters): array
    {
        $session = AuthSession::query()->create([
            'user_id' => $manager->id,
            'application' => 'administrativa',
            'device_id' => 'manager-device',
            'ip_address' => '127.0.0.1',
            'context_version' => $manager->context_version,
            'last_activity_at' => now(),
            'expires_at' => now()->addHours(8),
            'state' => 'ACTIVE',
        ]);
        $createdToken = $manager->createToken('administrativa', ['*'], now()->addMinutes(10));
        $createdToken->accessToken->forceFill([
            'auth_session_id' => $session->id,
            'context_version' => $manager->context_version,
        ])->save();
        $manager->withAccessToken($createdToken->accessToken);
        $plain = Str::random(64);
        $binding = new AuthorizationBinding(
            CriticalAction::CREDIT_INCREASE_DECISION,
            'credit_increase_requests',
            $requestId,
            $branch->public_id,
            $parameters,
        );
        $issuedAt = now();
        ReauthAuthorization::query()->create([
            'user_id' => $manager->id,
            'auth_session_id' => $session->id,
            'requester_user_id' => $manager->id,
            'method' => 'PASSWORD_TOTP',
            'action' => CriticalAction::CREDIT_INCREASE_DECISION->value,
            'resource_type' => 'credit_increase_requests',
            'record_id' => $requestId,
            'branch_id' => $branch->public_id,
            'parameters_hash' => $binding->parametersHash(),
            'context_version' => $manager->context_version,
            'token_hash' => hash('sha256', $plain),
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->clone()->addMinutes(5),
        ]);

        return [$manager, $plain];
    }

    /** @param \Closure(): mixed $operation */
    private function assertScopeDenied(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('La operación fuera de alcance debió rechazarse.');
        } catch (CreditRuleViolation $exception) {
            self::assertSame('AUTH_SCOPE_DENIED', $exception->errorCode());
        }
    }

    private function accessToken(User $user, string $application): string
    {
        $session = AuthSession::query()->create([
            'user_id' => $user->id,
            'application' => $application,
            'device_id' => 'api-test-device',
            'ip_address' => '127.0.0.1',
            'context_version' => $user->context_version,
            'last_activity_at' => now(),
            'expires_at' => now()->addHours(8),
            'state' => 'ACTIVE',
        ]);
        $created = $user->createToken($application, ['*'], now()->addMinutes(10));
        $created->accessToken->forceFill([
            'auth_session_id' => $session->id,
            'context_version' => $user->context_version,
        ])->save();

        return $created->plainTextToken;
    }
}
