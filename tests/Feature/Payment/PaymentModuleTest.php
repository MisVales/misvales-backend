<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Payment\Domain\Enums\BankImportStatus;
use App\Modules\Payment\Domain\Enums\BankMovementStatus;
use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\BankImportModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\BankMovementModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PaymentModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payment.idempotency_hmac_key', 'payment-idempotency-key-for-tests');
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_payment_schema_contains_separate_import_financial_review_and_excess_ledgers(): void
    {
        foreach ([
            'bank_imports',
            'bank_movements',
            'bank_folio_reservations',
            'payment_allocations',
            'payment_allocation_items',
            'payment_late_fee_markers',
            'payment_post_due_evaluations',
            'payment_clarifications',
            'manual_reconciliations',
            'excess_balances',
            'excess_applications',
            'refund_requests',
            'payment_idempotency_keys',
            'payment_audits',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_cashier_upload_is_fail_closed_until_the_bank_file_contract_is_defined(): void
    {
        $cashier = User::factory()->cashier()->create();
        Sanctum::actingAs($cashier);

        $response = $this->withHeader('Idempotency-Key', 'bank-import-0001')
            ->post('/api/v1/bank-imports', [
                'business_date' => '2026-07-25',
                'file' => UploadedFile::fake()->createWithContent('bank.csv', "referencia,monto,fecha,folio,concepto\n"),
            ]);

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'BANK_FILE_CONTRACT_UNDEFINED')
            ->assertHeader('X-Request-Id');
        self::assertSame(0, DB::table('bank_imports')->count());
    }

    public function test_administrator_cannot_upload_and_verifier_cannot_read_payment_operations(): void
    {
        $administrator = User::factory()->administrator()->create();
        Sanctum::actingAs($administrator);
        $this->withHeader('Idempotency-Key', 'admin-import-0001')
            ->post('/api/v1/bank-imports', [
                'business_date' => '2026-07-25',
                'file' => UploadedFile::fake()->create('bank.csv', 1, 'text/csv'),
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');

        $verifier = User::factory()->verifier()->create();
        Sanctum::actingAs($verifier);
        $this->getJson('/api/v1/bank-imports')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
    }

    public function test_distributor_marks_own_excess_as_credit_idempotently_without_recovering_line(): void
    {
        $branch = Branch::factory()->create();
        $distributor = User::factory()->distributor()->create(['branch_id' => $branch->id]);
        $excess = $this->excess($branch, $distributor, '75.2500');
        Sanctum::actingAs($distributor);

        $first = $this->withHeader('Idempotency-Key', 'choose-credit-0001')
            ->postJson('/api/v1/me/excess-balances/'.$excess->id.'/credit-balance', [
                'reason' => 'Conservar el excedente como saldo a favor.',
                'lock_version' => 1,
            ]);
        $first->assertOk()
            ->assertJsonPath('data.status', ExcessBalanceStatus::CREDIT_BALANCE->value)
            ->assertJsonPath('data.available_amount', '75.2500')
            ->assertJsonPath('data.lock_version', 2);

        $this->withHeader('Idempotency-Key', 'choose-credit-0001')
            ->postJson('/api/v1/me/excess-balances/'.$excess->id.'/credit-balance', [
                'reason' => 'Conservar el excedente como saldo a favor.',
                'lock_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.lock_version', 2);

        self::assertSame(ExcessBalanceStatus::CREDIT_BALANCE, $excess->refresh()->status);
        self::assertSame(1, DB::table('excess_audits')->where('action', 'EXCESS_MARKED_AS_CREDIT')->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'ExcessMarkedAsCredit')->count());
        self::assertSame(0, DB::table('credit_line_movements')->count());
    }

    public function test_excess_owner_scope_and_idempotency_payload_are_enforced(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->distributor()->create(['branch_id' => $branch->id]);
        $other = User::factory()->distributor()->create(['branch_id' => $branch->id]);
        $excess = $this->excess($branch, $owner, '50.0000');

        Sanctum::actingAs($other);
        $this->withHeader('Idempotency-Key', 'other-credit-0001')
            ->postJson('/api/v1/me/excess-balances/'.$excess->id.'/credit-balance', [
                'reason' => 'Intento fuera del alcance permitido.',
                'lock_version' => 1,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'EXCESS_BALANCE_NOT_FOUND');

        Sanctum::actingAs($owner);
        $this->withHeader('Idempotency-Key', 'owner-credit-0001')
            ->postJson('/api/v1/me/excess-balances/'.$excess->id.'/credit-balance', [
                'reason' => 'Conservar el excedente como saldo a favor.',
                'lock_version' => 1,
            ])->assertOk();
        $this->withHeader('Idempotency-Key', 'owner-credit-0001')
            ->postJson('/api/v1/me/excess-balances/'.$excess->id.'/credit-balance', [
                'reason' => 'Reutilización con contenido diferente.',
                'lock_version' => 2,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_cashier_reads_only_imports_from_own_branch_and_global_manager_reads_all(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->create(['branch_id' => $branch->id]);
        $otherCashier = User::factory()->cashier()->create(['branch_id' => $otherBranch->id]);
        $this->bankImport($branch, $cashier);
        $this->bankImport($otherBranch, $otherCashier);

        Sanctum::actingAs($cashier);
        $this->getJson('/api/v1/bank-imports')->assertOk()->assertJsonCount(1, 'data');

        $manager = User::factory()->generalManager()->create();
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/bank-imports')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_list_filters_reject_statuses_outside_the_resource_enum(): void
    {
        $cashier = User::factory()->cashier()->create();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/bank-imports?status=ARBITRARY')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['status']]]);
    }

    public function test_database_prevents_two_reservations_for_the_same_folio_scope(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->create(['branch_id' => $branch->id]);
        $import = $this->bankImport($branch, $cashier);
        $first = $this->movement($import, $branch, 1);
        $second = $this->movement($import, $branch, 2);
        DB::table('bank_folio_reservations')->insert([
            'id' => fake()->uuid(),
            'folio_scope' => 'approved-scope',
            'normalized_folio' => 'FOLIO-001',
            'first_movement_id' => $first->id,
            'reserved_at' => now('UTC'),
        ]);

        $this->expectException(QueryException::class);
        DB::table('bank_folio_reservations')->insert([
            'id' => fake()->uuid(),
            'folio_scope' => 'approved-scope',
            'normalized_folio' => 'FOLIO-001',
            'first_movement_id' => $second->id,
            'reserved_at' => now('UTC'),
        ]);
    }

    public function test_database_rejects_an_allocation_whose_breakdown_does_not_equal_applied_amount(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->create(['branch_id' => $branch->id]);
        $movement = $this->movement($this->bankImport($branch, $cashier), $branch, 1);

        $this->expectException(QueryException::class);
        DB::table('payment_allocations')->insert([
            ...$this->allocationPayload($movement, $cashier),
            'capital_amount' => '99.0000',
        ]);
    }

    public function test_confirmed_payment_allocation_is_immutable_in_postgresql(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->create(['branch_id' => $branch->id]);
        $movement = $this->movement($this->bankImport($branch, $cashier), $branch, 1);
        $payload = $this->allocationPayload($movement, $cashier);
        DB::table('payment_allocations')->insert($payload);

        $this->expectException(QueryException::class);
        DB::table('payment_allocations')->where('id', $payload['id'])->update([
            'balance_after' => '1.0000',
        ]);
    }

    private function bankImport(Branch $branch, User $cashier): BankImportModel
    {
        return BankImportModel::query()->create([
            'branch_id' => $branch->id,
            'uploaded_by' => $cashier->id,
            'media_file_id' => fake()->uuid(),
            'business_date' => '2026-07-25',
            'file_hash' => hash('sha256', fake()->uuid()),
            'original_name' => 'bank-file.csv',
            'status' => BankImportStatus::PROCESSED,
            'processing_started_at' => now('UTC'),
            'processing_finished_at' => now('UTC'),
        ]);
    }

    private function movement(BankImportModel $import, Branch $branch, int $row): BankMovementModel
    {
        return BankMovementModel::query()->create([
            'bank_import_id' => $import->id,
            'branch_id' => $branch->id,
            'row_number' => $row,
            'payment_reference_raw' => 'REL-001',
            'payment_reference_normalized' => 'REL-001',
            'amount' => '100.0000',
            'paid_at' => now('UTC'),
            'bank_folio_raw' => 'FOLIO-'.$row,
            'bank_folio_normalized' => 'FOLIO-'.$row,
            'bank_folio_scope' => 'approved-scope',
            'concept_raw' => 'Pago de prueba',
            'raw_payload' => ['row' => $row],
            'status' => BankMovementStatus::UNRECONCILED,
            'processed_at' => now('UTC'),
        ]);
    }

    private function excess(Branch $branch, User $distributor, string $amount): ExcessBalanceModel
    {
        $cashier = User::factory()->cashier()->create(['branch_id' => $branch->id]);
        $import = $this->bankImport($branch, $cashier);
        $movement = $this->movement($import, $branch, 1);
        $allocation = $this->allocationPayload($movement, $cashier);
        $allocation['received_amount'] = bcadd('100.0000', $amount, 4);
        $allocation['excess_amount'] = $amount;
        DB::table('payment_allocations')->insert($allocation);

        return ExcessBalanceModel::query()->create([
            'public_number' => 'EXC-'.str()->upper(str()->random(16)),
            'distributor_id' => $distributor->id,
            'branch_id' => $branch->id,
            'origin_relation_id' => fake()->uuid(),
            'bank_movement_id' => $movement->id,
            'payment_allocation_id' => $allocation['id'],
            'original_amount' => $amount,
            'retained_amount' => $amount,
            'available_amount' => '0.0000',
            'applied_amount' => '0.0000',
            'reserved_refund_amount' => '0.0000',
            'refunded_amount' => '0.0000',
            'currency' => 'MXN',
            'status' => ExcessBalanceStatus::PENDING_DECISION,
            'effective_paid_at' => now('UTC'),
        ]);
    }

    /** @return array<string, int|string|null> */
    private function allocationPayload(BankMovementModel $movement, User $cashier): array
    {
        return [
            'id' => fake()->uuid(),
            'relation_id' => fake()->uuid(),
            'bank_movement_id' => $movement->id,
            'excess_application_id' => null,
            'source_type' => 'BANK_MOVEMENT',
            'received_amount' => '100.0000',
            'applied_amount' => '100.0000',
            'excess_amount' => '0.0000',
            'late_fee_amount' => '0.0000',
            'interest_amount' => '0.0000',
            'insurance_amount' => '0.0000',
            'loan_commission_amount' => '0.0000',
            'capital_amount' => '100.0000',
            'balance_before' => '100.0000',
            'balance_after' => '0.0000',
            'effective_at' => now('UTC')->toIso8601String(),
            'applied_at' => now('UTC')->toIso8601String(),
            'application_mode' => 'AUTOMATIC',
            'manual_reconciliation_id' => null,
            'idempotency_key' => 'payment-allocation-'.fake()->uuid(),
            'created_by' => $cashier->id,
            'created_at' => now('UTC')->toIso8601String(),
        ];
    }
}
