<?php

declare(strict_types=1);

namespace Tests\Feature\ExcessBalance;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\DistributorAccessLink;
use App\Modules\ExcessBalance\Application\Contracts\CreditBalanceApplicationPort;
use App\Modules\ExcessBalance\Application\Contracts\ExcessReauthenticationPort;
use App\Modules\ExcessBalance\Application\Contracts\RefundExecutionPolicy;
use App\Modules\ExcessBalance\Application\DTOs\DetectedExcess;
use App\Modules\ExcessBalance\Application\Services\ApplyCreditBalanceToNextRelation;
use App\Modules\ExcessBalance\Application\Services\RegisterDetectedExcess;
use App\Modules\Payment\Domain\Enums\BankImportStatus;
use App\Modules\Payment\Domain\Enums\BankMovementStatus;
use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use App\Modules\Payment\Domain\Enums\RefundRequestStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\BankImportModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\BankMovementModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeCreditBalanceApplicationPort;
use Tests\Support\FakeExcessReauthentication;
use Tests\Support\FakeRefundExecutionPolicy;
use Tests\TestCase;

final class ExcessBalanceModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('excess_balance.idempotency_hmac_key', 'm12-idempotency-key-for-tests');
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_schema_reuses_m11_balances_and_adds_immutable_traceability(): void
    {
        foreach ([
            'excess_balances',
            'excess_applications',
            'refund_requests',
            'excess_ledger_entries',
            'excess_status_history',
            'excess_idempotency_keys',
            'excess_processed_events',
            'excess_audits',
            'excess_evidence_files',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_m11_registers_one_retained_excess_with_full_origin_traceability(): void
    {
        [$detected] = $this->detectedExcess('25.0000');
        $service = app(RegisterDetectedExcess::class);

        $first = $service->register($detected);
        $second = $service->register($detected);

        self::assertSame($first, $second);
        self::assertSame(1, DB::table('excess_balances')->count());
        self::assertSame(1, DB::table('excess_ledger_entries')->count());
        self::assertSame(1, DB::table('excess_status_history')->count());
        self::assertSame(2, DB::table('outbox_events')->where('idempotency_key', 'like', 'm12:%')->count());
        $balance = ExcessBalanceModel::query()->findOrFail($first['id']);
        self::assertSame('25.0000', (string) $balance->retained_amount);
        self::assertSame('0.0000', (string) $balance->available_amount);
        self::assertSame($detected->paymentAllocationId, $balance->payment_allocation_id);
        self::assertSame($detected->relationId, $balance->origin_relation_id);
    }

    public function test_only_owner_can_choose_credit_and_replay_is_idempotent(): void
    {
        [$detected, $owner] = $this->detectedExcess('25.0000');
        $balanceId = app(RegisterDetectedExcess::class)->register($detected)['id'];
        $other = User::factory()->distributor()->create(['branch_id' => $detected->branchId]);

        Sanctum::actingAs($other);
        $this->withHeader('Idempotency-Key', 'wrong-owner-credit')
            ->postJson('/api/v1/me/excess-balances/'.$balanceId.'/credit-balance', ['lock_version' => 1])
            ->assertNotFound();
        $this->assertDatabaseHas('excess_audits', [
            'action' => 'EXCESS_REQUEST_REJECTED',
            'result' => 'DENIED',
            'actor_id' => $other->id,
            'resource_type' => 'http_request',
            'resource_id' => $balanceId,
            'reason' => 'EXCESS_BALANCE_NOT_FOUND',
        ]);

        Sanctum::actingAs($owner);
        $first = $this->withHeader('Idempotency-Key', 'owner-credit-0001')
            ->postJson('/api/v1/me/excess-balances/'.$balanceId.'/credit-balance', ['lock_version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', ExcessBalanceStatus::CREDIT_BALANCE->value)
            ->assertJsonPath('data.available_amount', '25.0000')
            ->assertJsonPath('data.lock_version', 2);
        $this->withHeader('Idempotency-Key', 'owner-credit-0001')
            ->postJson('/api/v1/me/excess-balances/'.$balanceId.'/credit-balance', ['lock_version' => 1])
            ->assertOk()
            ->assertExactJson($first->json());

        $this->getJson('/api/v1/me/excess-balances/'.$balanceId)
            ->assertOk()
            ->assertJsonPath('data.saldo_a_favor_disponible', '25.00')
            ->assertJsonPath('data.acciones_permitidas', []);
        self::assertSame(2, DB::table('excess_ledger_entries')->count());
    }

    public function test_refund_request_reserves_full_amount_and_rejection_does_not_release_it(): void
    {
        $this->app->bind(ExcessReauthenticationPort::class, FakeExcessReauthentication::class);
        [$detected, $owner, $branch] = $this->detectedExcess('25.0000');
        $balanceId = app(RegisterDetectedExcess::class)->register($detected)['id'];

        Sanctum::actingAs($owner);
        $requestId = $this->withHeader('Idempotency-Key', 'refund-request-0001')
            ->postJson('/api/v1/me/excess-balances/'.$balanceId.'/refund-requests', [
                'lock_version' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.requested_amount', '25.0000')
            ->json('data.id');

        $balance = ExcessBalanceModel::query()->findOrFail($balanceId);
        self::assertSame('25.0000', (string) $balance->reserved_refund_amount);
        self::assertSame('0.0000', (string) $balance->retained_amount);
        self::assertSame(ExcessBalanceStatus::REFUND_PENDING, $balance->status);

        $manager = User::factory()->sucursalManager()->create(['branch_id' => $branch->id]);
        Sanctum::actingAs($manager);
        $this->withHeader('Idempotency-Key', 'refund-reject-0001')
            ->postJson('/api/v1/refund-requests/'.$requestId.'/reject', [
                'lock_version' => 1,
                'reauthentication_token' => str_repeat('a', 64),
                'reason' => 'La solicitud no procede.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', RefundRequestStatus::REJECTED->value);

        self::assertSame(
            '25.0000',
            (string) ExcessBalanceModel::query()->findOrFail($balanceId)->reserved_refund_amount,
        );
        self::assertSame(2, DB::table('excess_ledger_entries')->count());
    }

    public function test_authorized_refund_is_completed_once_by_cashier_with_separation_of_functions(): void
    {
        $this->app->bind(ExcessReauthenticationPort::class, FakeExcessReauthentication::class);
        $this->app->bind(RefundExecutionPolicy::class, FakeRefundExecutionPolicy::class);
        [$detected, $owner, $branch] = $this->detectedExcess('25.0000');
        $balanceId = app(RegisterDetectedExcess::class)->register($detected)['id'];

        Sanctum::actingAs($owner);
        $requestId = $this->withHeader('Idempotency-Key', 'refund-request-0002')
            ->postJson('/api/v1/me/excess-balances/'.$balanceId.'/refund-requests', ['lock_version' => 1])
            ->json('data.id');

        $manager = User::factory()->generalManager()->create();
        Sanctum::actingAs($manager);
        $this->withHeader('Idempotency-Key', 'refund-authorize-0001')
            ->postJson('/api/v1/refund-requests/'.$requestId.'/authorize', [
                'lock_version' => 1,
                'reauthentication_token' => str_repeat('b', 64),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', RefundRequestStatus::AUTHORIZED->value);

        $cashier = User::factory()->cashier()->create(['branch_id' => $branch->id]);
        Sanctum::actingAs($cashier);
        $first = $this->withHeader('Idempotency-Key', 'refund-complete-0001')
            ->postJson('/api/v1/refund-requests/'.$requestId.'/complete', [
                'lock_version' => 2,
                'refund_date' => '2026-07-26',
                'method' => 'FAKE_APPROVED_METHOD',
                'reference' => 'EXT-001',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', RefundRequestStatus::COMPLETED->value);
        $this->withHeader('Idempotency-Key', 'refund-complete-0001')
            ->postJson('/api/v1/refund-requests/'.$requestId.'/complete', [
                'lock_version' => 2,
                'refund_date' => '2026-07-26',
                'method' => 'FAKE_APPROVED_METHOD',
                'reference' => 'EXT-001',
            ])
            ->assertOk()
            ->assertExactJson($first->json());

        $request = RefundRequestModel::query()->findOrFail($requestId);
        $balance = ExcessBalanceModel::query()->findOrFail($balanceId);
        self::assertSame($manager->id, $request->authorized_by);
        self::assertSame($cashier->id, $request->executed_by);
        self::assertSame('25.0000', (string) $balance->refunded_amount);
        self::assertSame('0.0000', (string) $balance->reserved_refund_amount);
        self::assertSame(ExcessBalanceStatus::REFUNDED, $balance->status);
    }

    public function test_credit_balance_application_uses_m11_origin_without_fake_bank_movement(): void
    {
        [$detected, $owner, $branch] = $this->detectedExcess('25.0000');
        $balanceId = app(RegisterDetectedExcess::class)->register($detected)['id'];
        Sanctum::actingAs($owner);
        $this->withHeader('Idempotency-Key', 'owner-credit-application')
            ->postJson('/api/v1/me/excess-balances/'.$balanceId.'/credit-balance', ['lock_version' => 1])
            ->assertOk();

        $this->app->instance(
            CreditBalanceApplicationPort::class,
            new FakeCreditBalanceApplicationPort('20.0000'),
        );
        $result = app(ApplyCreditBalanceToNextRelation::class)->execute(
            (string) Str::uuid(),
            (string) Str::uuid(),
            $owner->id,
            $branch->id,
        );

        self::assertSame('APPLIED', $result['result']);
        self::assertSame('20.0000', $result['applied_amount']);
        self::assertSame('15.0000', $result['capital_amount']);
        $balance = ExcessBalanceModel::query()->findOrFail($balanceId);
        self::assertSame(ExcessBalanceStatus::PARTIALLY_APPLIED, $balance->status);
        self::assertSame('5.0000', (string) $balance->available_amount);
        self::assertSame('20.0000', (string) $balance->applied_amount);
        $allocation = DB::table('payment_allocations')->where('id', $result['payment_allocation_id'])->first();
        self::assertSame('CREDIT_BALANCE', $allocation->source_type);
        self::assertNull($allocation->bank_movement_id);
        self::assertSame('15.0000', $allocation->capital_amount);
    }

    public function test_read_scopes_cover_all_operational_roles_without_granting_verifier_access(): void
    {
        [$firstDetected, $firstOwner, $firstBranch] = $this->detectedExcess('25.0000');
        [$secondDetected, , $secondBranch] = $this->detectedExcess('30.0000');
        $firstId = app(RegisterDetectedExcess::class)->register($firstDetected)['id'];
        $secondId = app(RegisterDetectedExcess::class)->register($secondDetected)['id'];

        $cashier = User::factory()->cashier()->create(['branch_id' => $firstBranch->id]);
        Sanctum::actingAs($cashier);
        $this->getJson('/api/v1/excess-balances')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/excess-balances/'.$secondId)
            ->assertNotFound();

        $manager = User::factory()->sucursalManager()->create(['branch_id' => $firstBranch->id]);
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/excess-balances')->assertOk()->assertJsonCount(1, 'data');

        $coordinator = User::factory()->coordinator()->create(['branch_id' => $firstBranch->id]);
        $generalManager = User::factory()->generalManager()->create();
        DistributorAccessLink::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $firstOwner->id,
            'external_request_id' => (string) Str::uuid(),
            'external_distributor_id' => (string) Str::uuid(),
            'branch_id' => $firstBranch->id,
            'coordinator_user_id' => $coordinator->id,
            'authorized_by' => $generalManager->id,
            'initial_credit_line' => '1000.00',
            'authorized_at' => now('UTC'),
        ]);
        Sanctum::actingAs($coordinator);
        $this->getJson('/api/v1/excess-balances')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/excess-balances/'.$firstId)->assertOk();

        Sanctum::actingAs($generalManager);
        $this->getJson('/api/v1/excess-balances')->assertOk()->assertJsonCount(2, 'data');

        $administrator = User::factory()->administrator()->create();
        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/excess-balances')->assertOk()->assertJsonCount(2, 'data');
        $this->withHeader('Idempotency-Key', 'admin-cannot-decide')
            ->postJson('/api/v1/me/excess-balances/'.$firstId.'/credit-balance', ['lock_version' => 1])
            ->assertNotFound();

        $verifier = User::factory()->verifier()->create(['branch_id' => $secondBranch->id]);
        Sanctum::actingAs($verifier);
        $this->getJson('/api/v1/excess-balances')->assertForbidden();
    }

    public function test_conflicting_second_destination_does_not_duplicate_the_ledger(): void
    {
        [$detected, $owner] = $this->detectedExcess('25.0000');
        $balanceId = app(RegisterDetectedExcess::class)->register($detected)['id'];
        Sanctum::actingAs($owner);

        $this->withHeader('Idempotency-Key', 'first-destination')
            ->postJson('/api/v1/me/excess-balances/'.$balanceId.'/credit-balance', ['lock_version' => 1])
            ->assertOk();
        $this->withHeader('Idempotency-Key', 'second-destination')
            ->postJson('/api/v1/me/excess-balances/'.$balanceId.'/refund-requests', ['lock_version' => 2])
            ->assertConflict()
            ->assertJsonPath('error.code', 'EXCESS_STATE_CONFLICT');

        self::assertSame(2, DB::table('excess_ledger_entries')->where('excess_balance_id', $balanceId)->count());
        self::assertSame(0, DB::table('refund_requests')->where('excess_balance_id', $balanceId)->count());
    }

    /** @return array{DetectedExcess, User, Branch} */
    private function detectedExcess(string $excessAmount): array
    {
        $branch = Branch::factory()->create();
        $distributor = User::factory()->distributor()->create(['branch_id' => $branch->id]);
        $cashier = User::factory()->cashier()->create(['branch_id' => $branch->id]);
        $import = BankImportModel::query()->create([
            'branch_id' => $branch->id,
            'uploaded_by' => $cashier->id,
            'media_file_id' => (string) Str::uuid(),
            'business_date' => '2026-07-25',
            'file_hash' => hash('sha256', (string) Str::uuid()),
            'original_name' => 'bank-file.csv',
            'status' => BankImportStatus::PROCESSED,
            'processing_started_at' => now('UTC'),
            'processing_finished_at' => now('UTC'),
        ]);
        $movement = BankMovementModel::query()->create([
            'bank_import_id' => $import->id,
            'branch_id' => $branch->id,
            'row_number' => 1,
            'payment_reference_raw' => 'REL-ORIGIN',
            'payment_reference_normalized' => 'REL-ORIGIN',
            'amount' => bcadd('100.0000', $excessAmount, 4),
            'paid_at' => '2026-07-25T12:00:00Z',
            'bank_folio_raw' => 'FOLIO-ORIGIN',
            'bank_folio_normalized' => 'FOLIO-ORIGIN',
            'bank_folio_scope' => 'approved-scope',
            'concept_raw' => 'Pago conciliado de prueba',
            'raw_payload' => ['row' => 1],
            'status' => BankMovementStatus::RECONCILED,
            'processed_at' => now('UTC'),
        ]);
        $relationId = (string) Str::uuid();
        $allocationId = (string) Str::uuid();
        DB::table('payment_allocations')->insert([
            'id' => $allocationId,
            'relation_id' => $relationId,
            'bank_movement_id' => $movement->id,
            'excess_application_id' => null,
            'source_type' => 'BANK_MOVEMENT',
            'received_amount' => bcadd('100.0000', $excessAmount, 4),
            'applied_amount' => '100.0000',
            'excess_amount' => $excessAmount,
            'late_fee_amount' => '0.0000',
            'interest_amount' => '0.0000',
            'insurance_amount' => '0.0000',
            'loan_commission_amount' => '0.0000',
            'capital_amount' => '100.0000',
            'balance_before' => '100.0000',
            'balance_after' => '0.0000',
            'effective_at' => '2026-07-25T12:00:00Z',
            'applied_at' => now('UTC'),
            'application_mode' => 'AUTOMATIC',
            'manual_reconciliation_id' => null,
            'idempotency_key' => 'origin-payment-'.$allocationId,
            'created_by' => $cashier->id,
            'created_at' => now('UTC'),
        ]);

        return [
            new DetectedExcess(
                bankMovementId: $movement->id,
                paymentAllocationId: $allocationId,
                relationId: $relationId,
                distributorId: $distributor->id,
                branchId: $branch->id,
                paidAmount: bcadd('100.0000', $excessAmount, 4),
                previousBalance: '100.0000',
                appliedAmount: '100.0000',
                excessAmount: $excessAmount,
                effectivePaidAt: CarbonImmutable::parse('2026-07-25T12:00:00Z'),
                idempotencyKey: 'detected-'.$allocationId,
                correlationId: (string) Str::uuid(),
            ),
            $distributor,
            $branch,
        ];
    }
}
