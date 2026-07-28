<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Client\Domain\Assignments\AssignmentType;
use App\Modules\Client\Domain\Security\ExactMatchHmac;
use App\Modules\Client\Domain\Security\SensitiveDataProtector;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Persistence\Models\ClientAddress;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Distributor\Persistence\Models\Distributor;
use App\Modules\Distributor\Persistence\Models\DistributorCategoryAssignment;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VoucherGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('client.encryption_key', base64_encode(str_repeat('c', 32)));
        config()->set('client.hmac_key', 'client-hmac-key-for-m08-tests');
        config()->set('voucher.idempotency_hmac_key', 'voucher-idempotency-key-for-m08-tests');
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_distributor_generates_prevale_with_snapshot_installments_outbox_and_no_credit_movement(): void
    {
        $context = $this->context();
        $this->actingAs($context['user'], 'sanctum');

        $response = $this->withHeaders($this->headers('generation-one'))
            ->postJson('/api/v1/vouchers', [
                'client_id' => $context['client']->id,
                'product_id' => $context['product_id'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'PREVALE')
            ->assertJsonPath('data.status', 'GENERADO')
            ->assertJsonPath('data.financial_summary.loan_commission', '1500.00')
            ->assertJsonPath('data.financial_summary.total_interest', '6000.00')
            ->assertJsonPath('data.financial_summary.misvales_total', '22600.00')
            ->assertJsonPath('data.financial_summary.distributor_profit', '900.00')
            ->assertJsonCount(8, 'data.installments')
            ->assertJsonMissingPath('data.client.curp')
            ->assertJsonMissingPath('data.client.bank_account');

        $voucherId = (string) $response->json('data.id');
        self::assertSame(
            '15000.0000',
            (string) DB::table('credit_lines')->where('id', $context['credit_line_id'])->value('available_balance'),
        );
        self::assertSame(0, DB::table('credit_line_movements')->count());
        self::assertSame(1, DB::table('voucher_financial_snapshots')->where('voucher_id', $voucherId)->count());
        self::assertSame(8, DB::table('voucher_installments')->where('voucher_id', $voucherId)->count());
        self::assertSame(1, DB::table('voucher_outbox_events')->where('event_type', 'VoucherGenerated')->count());
        self::assertSame(1, DB::table('voucher_operation_history')->where('voucher_id', $voucherId)->count());
        self::assertSame(1, DB::table('voucher_audits')->where('event_type', 'VOUCHER_GENERATED')->count());
        self::assertSame(
            '15000.0000',
            (string) DB::table('voucher_installments')->where('voucher_id', $voucherId)->sum('capital_amount'),
        );
    }

    public function test_generation_is_persistently_idempotent_and_next_folio_is_digital(): void
    {
        $context = $this->context();
        $this->actingAs($context['user'], 'sanctum');
        $body = ['client_id' => $context['client']->id, 'product_id' => $context['product_id']];

        $first = $this->withHeaders($this->headers('same-generation'))->postJson('/api/v1/vouchers', $body)
            ->assertCreated();
        $replay = $this->withHeaders($this->headers('same-generation'))->postJson('/api/v1/vouchers', $body)
            ->assertOk();
        self::assertSame($first->json('data.id'), $replay->json('data.id'));
        self::assertSame(1, DB::table('vouchers')->count());

        $this->withHeaders($this->headers('next-generation'))->postJson('/api/v1/vouchers', $body)
            ->assertCreated()
            ->assertJsonPath('data.type', 'VALE_DIGITAL');
        self::assertSame(2, DB::table('vouchers')->count());
    }

    public function test_idempotency_key_with_different_body_is_rejected(): void
    {
        $context = $this->context();
        $otherClient = $this->client($context['user'], $context['distributor']->id, $context['branch']);
        $this->actingAs($context['user'], 'sanctum');

        $this->withHeaders($this->headers('reused-generation'))->postJson('/api/v1/vouchers', [
            'client_id' => $context['client']->id,
            'product_id' => $context['product_id'],
        ])->assertCreated();

        $this->withHeaders($this->headers('reused-generation'))->postJson('/api/v1/vouchers', [
            'client_id' => $otherClient->id,
            'product_id' => $context['product_id'],
        ])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        self::assertSame(1, DB::table('vouchers')->count());
    }

    public function test_transferred_client_receives_digital_voucher_even_without_local_voucher_history(): void
    {
        $context = $this->context();
        DB::table('client_distributor_assignments')
            ->where('client_id', $context['client']->id)
            ->update(['assignment_type' => 'AUTHORIZED_TRANSFER']);
        $this->actingAs($context['user'], 'sanctum');

        $this->withHeaders($this->headers('transferred-client'))->postJson('/api/v1/vouchers', [
            'client_id' => $context['client']->id,
            'product_id' => $context['product_id'],
        ])->assertCreated()->assertJsonPath('data.type', 'VALE_DIGITAL');
    }

    public function test_informational_client_debt_never_blocks_generation(): void
    {
        $context = $this->context();
        $assignmentId = DB::table('client_distributor_assignments')
            ->where('client_id', $context['client']->id)
            ->value('id');
        DB::table('client_portfolio_entries')->insert([
            'id' => (string) Str::uuid(),
            'client_id' => $context['client']->id,
            'distributor_id' => $context['distributor']->id,
            'assignment_id' => $assignmentId,
            'entry_type' => 'VOUCHER',
            'amount' => '9000.0000',
            'informational_status' => 'PENDING',
            'occurred_on' => now('America/Monterrey')->toDateString(),
            'created_by' => $context['user']->id,
            'idempotency_key' => 'portfolio-'.Str::uuid(),
            'request_hash' => hash('sha256', 'synthetic-debt-'.$context['client']->id),
            'lock_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->actingAs($context['user'], 'sanctum');

        $this->withHeaders($this->headers('client-with-debt'))->postJson('/api/v1/vouchers', [
            'client_id' => $context['client']->id,
            'product_id' => $context['product_id'],
        ])->assertCreated()->assertJsonPath('data.status', 'GENERADO');
    }

    public function test_generation_requires_headers_and_distributor_role(): void
    {
        $context = $this->context();
        $this->actingAs($context['user'], 'sanctum')
            ->postJson('/api/v1/vouchers', [
                'client_id' => $context['client']->id,
                'product_id' => $context['product_id'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $cashier = User::factory()->cashier()->create([
            'branch_id' => $context['branch']->id,
            'state' => AccountState::ACTIVE,
        ]);
        $this->actingAs($cashier, 'sanctum');
        $this->withHeaders($this->headers('cashier-generation'))->postJson('/api/v1/vouchers', [
            'client_id' => $context['client']->id,
            'product_id' => $context['product_id'],
        ])->assertForbidden();
        self::assertSame(0, DB::table('vouchers')->count());
    }

    public function test_active_fifty_percent_restriction_is_validated_and_bound_without_consuming_credit(): void
    {
        $context = $this->context('30000.0000');
        $restrictionId = DB::table('credit_usage_restrictions')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'credit_line_id' => $context['credit_line_id'],
            'trigger_type' => 'INITIAL_AUTHORIZATION',
            'trigger_id' => 'initial-'.$context['user']->id,
            'base_total_authorized' => '30000.0000',
            'percentage' => '0.5000',
            'tolerance_amount' => '500.0000',
            'reference_amount' => '15000.0000',
            'status' => 'ACTIVE',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->actingAs($context['user'], 'sanctum');

        $response = $this->withHeaders($this->headers('restricted-generation'))
            ->postJson('/api/v1/vouchers', [
                'client_id' => $context['client']->id,
                'product_id' => $context['product_id'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.credit_validation.special_rule_applied', true)
            ->assertJsonPath('data.credit_validation.minimum_allowed', '14500.00')
            ->assertJsonPath('data.credit_validation.maximum_allowed', '15500.00');

        self::assertSame('BOUND', DB::table('credit_usage_restrictions')->where('id', $restrictionId)->value('status'));
        self::assertSame(
            $response->json('data.id'),
            DB::table('credit_usage_restrictions')->where('id', $restrictionId)->value('bound_voucher_id'),
        );
        self::assertSame(0, DB::table('credit_line_movements')->count());
    }

    public function test_foreign_client_and_inactive_distributor_are_blocked_and_recorded_in_outbox(): void
    {
        $owner = $this->context();
        $other = $this->context();
        $this->actingAs($owner['user'], 'sanctum');

        $this->withHeaders($this->headers('foreign-client'))->postJson('/api/v1/vouchers', [
            'client_id' => $other['client']->id,
            'product_id' => $owner['product_id'],
        ])->assertNotFound()->assertJsonPath('error.code', 'CLIENT_NOT_ASSIGNED_TO_DISTRIBUTOR');

        DB::table('distributors')->where('id', $owner['distributor']->id)->update(['status' => 'INACTIVE']);
        $this->withHeaders($this->headers('inactive-distributor'))->postJson('/api/v1/vouchers', [
            'client_id' => $owner['client']->id,
            'product_id' => $owner['product_id'],
        ])->assertConflict()->assertJsonPath('error.code', 'DISTRIBUTOR_INACTIVE');

        self::assertSame(0, DB::table('vouchers')->count());
        self::assertSame(2, DB::table('voucher_outbox_events')
            ->where('event_type', 'VoucherGenerationBlocked')
            ->count());
        self::assertSame(2, DB::table('voucher_audits')
            ->where('event_type', 'VOUCHER_GENERATION_BLOCKED')
            ->count());
    }

    public function test_confirmed_distributor_delinquency_blocks_generation(): void
    {
        $context = $this->context();
        DB::table('distributor_risk_profiles')->insert([
            'id' => (string) Str::uuid(),
            'distributor_id' => $context['user']->id,
            'current_branch_id' => $context['branch']->id,
            'consecutive_breaches' => 3,
            'overdue_balance' => '5000.0000',
            'delinquency_status' => 'DELINQUENT',
            'blocked_for_new_vouchers' => true,
            'delinquency_applied_at' => now('UTC'),
            'profile_status' => 'CURRENT',
            'lock_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->actingAs($context['user'], 'sanctum');

        $this->withHeaders($this->headers('delinquent-distributor'))->postJson('/api/v1/vouchers', [
            'client_id' => $context['client']->id,
            'product_id' => $context['product_id'],
        ])->assertConflict()->assertJsonPath('error.code', 'DISTRIBUTOR_DELINQUENT');

        self::assertSame(0, DB::table('vouchers')->count());
        self::assertSame(1, DB::table('voucher_outbox_events')
            ->where('event_type', 'VoucherGenerationBlocked')
            ->count());
    }

    public function test_product_and_assigned_category_must_be_published_and_current(): void
    {
        $inactiveProduct = $this->context();
        DB::table('products')->where('public_id', $inactiveProduct['product_id'])->update(['status' => 'INACTIVE']);
        $this->actingAs($inactiveProduct['user'], 'sanctum');
        $this->withHeaders($this->headers('inactive-product'))->postJson('/api/v1/vouchers', [
            'client_id' => $inactiveProduct['client']->id,
            'product_id' => $inactiveProduct['product_id'],
        ])->assertNotFound()->assertJsonPath('error.code', 'PRODUCT_NOT_AVAILABLE');

        $expiredCategory = $this->context();
        DB::table('category_versions')
            ->where('public_id', $expiredCategory['category_version_id'])
            ->update(['effective_to' => now('UTC')->subSecond()]);
        $this->actingAs($expiredCategory['user'], 'sanctum');
        $this->withHeaders($this->headers('expired-category'))->postJson('/api/v1/vouchers', [
            'client_id' => $expiredCategory['client']->id,
            'product_id' => $expiredCategory['product_id'],
        ])->assertConflict()->assertJsonPath('error.code', 'DISTRIBUTOR_CATEGORY_NOT_AVAILABLE');

        self::assertSame(0, DB::table('vouchers')->count());
    }

    public function test_credit_insufficiency_fifty_percent_range_and_occupied_restriction_are_distinct(): void
    {
        $insufficient = $this->context('14999.0000');
        $this->actingAs($insufficient['user'], 'sanctum');
        $this->withHeaders($this->headers('insufficient-credit'))->postJson('/api/v1/vouchers', [
            'client_id' => $insufficient['client']->id,
            'product_id' => $insufficient['product_id'],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'CREDIT_INSUFFICIENT');

        $outsideRange = $this->context('40000.0000');
        $this->restriction($outsideRange, '40000.0000');
        $this->actingAs($outsideRange['user'], 'sanctum');
        $this->withHeaders($this->headers('outside-fifty-range'))->postJson('/api/v1/vouchers', [
            'client_id' => $outsideRange['client']->id,
            'product_id' => $outsideRange['product_id'],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'CREDIT_50_PERCENT_RULE_NOT_SATISFIED');

        $occupied = $this->context('30000.0000');
        $this->restriction($occupied, '30000.0000', (string) Str::uuid());
        $this->actingAs($occupied['user'], 'sanctum');
        $this->withHeaders($this->headers('occupied-restriction'))->postJson('/api/v1/vouchers', [
            'client_id' => $occupied['client']->id,
            'product_id' => $occupied['product_id'],
        ])->assertConflict()->assertJsonPath('error.code', 'CREDIT_RESTRICTION_ALREADY_LINKED');

        self::assertSame(0, DB::table('vouchers')->count());
        self::assertSame(0, DB::table('credit_line_movements')->count());
    }

    public function test_financial_snapshot_is_protected_by_postgresql(): void
    {
        $context = $this->context();
        $this->actingAs($context['user'], 'sanctum');
        $voucherId = (string) $this->withHeaders($this->headers('immutable-snapshot'))
            ->postJson('/api/v1/vouchers', [
                'client_id' => $context['client']->id,
                'product_id' => $context['product_id'],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->expectException(QueryException::class);
        DB::table('voucher_financial_snapshots')
            ->where('voucher_id', $voucherId)
            ->update(['capital_amount' => '1.0000']);
    }

    public function test_list_scope_follows_distributor_branch_coordinator_and_global_roles(): void
    {
        $own = $this->context();
        $other = $this->context();
        foreach ([[$own, 'own-scope'], [$other, 'other-scope']] as [$context, $key]) {
            $this->actingAs($context['user'], 'sanctum');
            $this->withHeaders($this->headers($key))->postJson('/api/v1/vouchers', [
                'client_id' => $context['client']->id,
                'product_id' => $context['product_id'],
            ])->assertCreated();
        }

        $this->actingAs($own['user'], 'sanctum')
            ->getJson('/api/v1/vouchers')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $cashier = User::factory()->cashier()->create(['branch_id' => $own['branch']->id]);
        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/v1/vouchers')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $coordinator = User::factory()->coordinator()->create(['branch_id' => $own['branch']->id]);
        DB::table('distributor_access_links')->insert([
            'public_id' => (string) Str::uuid(),
            'user_id' => $own['user']->id,
            'external_request_id' => 'request-'.Str::uuid(),
            'external_distributor_id' => $own['distributor']->id,
            'branch_id' => $own['branch']->id,
            'coordinator_user_id' => $coordinator->id,
            'authorized_by' => $coordinator->id,
            'initial_credit_line' => '15000.00',
            'authorized_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->actingAs($coordinator, 'sanctum')
            ->getJson('/api/v1/vouchers')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $branchManager = User::factory()->sucursalManager()->create(['branch_id' => $own['branch']->id]);
        $this->actingAs($branchManager, 'sanctum')
            ->getJson('/api/v1/vouchers')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $administrator = User::factory()->administrator()->create();
        $this->actingAs($administrator, 'sanctum')
            ->getJson('/api/v1/vouchers')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->withHeaders($this->headers('administrator-cannot-generate'))
            ->postJson('/api/v1/vouchers', [
                'client_id' => $own['client']->id,
                'product_id' => $own['product_id'],
            ])
            ->assertForbidden();

        $verifier = User::factory()->verifier()->create(['branch_id' => $own['branch']->id]);
        $this->actingAs($verifier, 'sanctum')
            ->getJson('/api/v1/vouchers')
            ->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function context(string $credit = '15000.0000'): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->distributor()->create([
            'branch_id' => $branch->id,
            'state' => AccountState::ACTIVE,
        ]);
        $distributor = new Distributor;
        $distributor->forceFill([
            'distributor_number' => 'D-'.Str::upper(Str::random(8)),
            'onboarding_application_id' => (string) Str::uuid(),
            'user_id' => $user->public_id,
            'branch_id' => $branch->public_id,
            'status' => 'ACTIVE',
            'activated_at' => now('UTC'),
            'activation_operation_id' => (string) Str::uuid(),
            'lock_version' => 1,
        ])->save();
        [$categoryId, $categoryVersionId] = $this->category($user);
        $assignment = new DistributorCategoryAssignment;
        $assignment->forceFill([
            'distributor_id' => $distributor->id,
            'category_id' => $categoryId,
            'category_version_id' => $categoryVersionId,
            'profit_rate_snapshot' => '0.0600',
            'effective_from' => now('UTC')->subMinute(),
            'assigned_by' => $user->public_id,
            'assigned_role' => 'DISTRIBUTOR',
            'assigned_branch_id' => $branch->public_id,
            'reason' => 'Asignación sintética.',
            'idempotency_key' => 'category-'.Str::uuid(),
        ])->save();
        $client = $this->client($user, $distributor->id, $branch);
        $productId = $this->product($user);
        $creditLineId = DB::table('credit_lines')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'distributor_id' => $user->id,
            'total_authorized' => $credit,
            'used_balance' => '0.0000',
            'available_balance' => $credit,
            'recovered_capital_total' => '0.0000',
            'lock_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return compact(
            'branch',
            'user',
            'distributor',
            'client',
            'productId',
            'creditLineId',
            'categoryId',
            'categoryVersionId',
        )
            + [
                'product_id' => $productId,
                'credit_line_id' => $creditLineId,
                'category_id' => $categoryId,
                'category_version_id' => $categoryVersionId,
            ];
    }

    /** @param array<string, mixed> $context */
    private function restriction(array $context, string $lineTotal, ?string $boundVoucherId = null): int
    {
        return DB::table('credit_usage_restrictions')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'credit_line_id' => $context['credit_line_id'],
            'trigger_type' => 'INITIAL_AUTHORIZATION',
            'trigger_id' => 'restriction-'.Str::uuid(),
            'base_total_authorized' => $lineTotal,
            'percentage' => '0.5000',
            'tolerance_amount' => '500.0000',
            'reference_amount' => bcmul($lineTotal, '0.5000', 4),
            'status' => $boundVoucherId === null ? 'ACTIVE' : 'BOUND',
            'bound_voucher_id' => $boundVoucherId,
            'bound_at' => $boundVoucherId === null ? null : now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function client(User $user, string $distributorId, Branch $branch): Client
    {
        $protector = $this->app->make(SensitiveDataProtector::class);
        $hmac = $this->app->make(ExactMatchHmac::class);
        $suffix = Str::upper(Str::random(4));
        $client = new Client;
        $client->forceFill([
            'given_names' => 'Cliente',
            'surnames' => 'Sintético '.$suffix,
            'curp_ciphertext' => $protector->encrypt('ABCD010101HDFRR'.substr($suffix, 0, 3)),
            'curp_hmac' => $hmac->make('ABCD010101HDFRR'.substr($suffix, 0, 3)),
            'curp_last4' => 'RN09',
            'created_by' => $user->id,
            'registration_operation_id' => 'registration-'.Str::uuid(),
            'lock_version' => 1,
        ])->save();
        $assignment = new ClientDistributorAssignment;
        $assignment->forceFill([
            'client_id' => $client->id,
            'distributor_id' => $distributorId,
            'branch_id_snapshot' => $branch->id,
            'effective_from' => now('UTC'),
            'assignment_type' => AssignmentType::INITIAL,
            'active_slot' => true,
            'created_at' => now('UTC'),
        ])->save();
        $address = new ClientAddress;
        $address->forceFill([
            'client_id' => $client->id,
            'street_ciphertext' => $protector->encrypt('Calle sintética'),
            'exterior_number_ciphertext' => $protector->encrypt('100'),
            'neighborhood_ciphertext' => $protector->encrypt('Centro'),
            'postal_code_ciphertext' => $protector->encrypt('64000'),
            'municipality_ciphertext' => $protector->encrypt('Monterrey'),
            'city_ciphertext' => $protector->encrypt('Monterrey'),
            'state_ciphertext' => $protector->encrypt('Nuevo León'),
            'address_fingerprint_hmac' => $hmac->make('address-'.$client->id),
            'normalization_version' => 'v1',
            'effective_from' => now('UTC'),
            'created_by' => $user->id,
            'active_slot' => true,
            'created_at' => now('UTC'),
        ])->save();

        return $client;
    }

    /** @return array{string, string} */
    private function category(User $user): array
    {
        $categoryId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $internalId = DB::table('categories')->insertGetId([
            'public_id' => $categoryId,
            'status' => 'PUBLISHED',
            'created_by' => $user->id,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('category_versions')->insert([
            'public_id' => $versionId,
            'category_id' => $internalId,
            'version_number' => 1,
            'name' => 'Categoría seis',
            'description' => 'Categoría sintética para M08.',
            'distributor_profit_rate' => '0.0600',
            'status' => 'PUBLISHED',
            'effective_from' => now('UTC')->subMinute(),
            'created_by' => $user->id,
            'published_by' => $user->id,
            'published_at' => now('UTC'),
            'reason' => 'Publicación sintética.',
            'lock_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return [$categoryId, $versionId];
    }

    private function product(User $user): string
    {
        $productId = (string) Str::uuid();
        $internalId = DB::table('products')->insertGetId([
            'public_id' => $productId,
            'status' => 'PUBLISHED',
            'created_by' => $user->id,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('product_versions')->insert([
            'public_id' => (string) Str::uuid(),
            'product_id' => $internalId,
            'version_number' => 1,
            'amount' => '15000.0000',
            'loan_commission_rate' => '0.1000',
            'interest_rate_per_fortnight' => '0.0500',
            'insurance_amount' => '100.0000',
            'fortnight_count' => 8,
            'status' => 'PUBLISHED',
            'effective_from' => now('UTC')->subMinute(),
            'created_by' => $user->id,
            'published_by' => $user->id,
            'published_at' => now('UTC'),
            'reason' => 'Publicación sintética.',
            'lock_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $productId;
    }

    /** @return array<string, string> */
    private function headers(string $key): array
    {
        return [
            'Idempotency-Key' => $key.'-idempotency',
            'X-Request-Id' => (string) Str::uuid(),
        ];
    }
}
