<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use App\Modules\Client\Application\Contracts\DistributorProfile;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Domain\Assignments\AssignmentType;
use App\Modules\Client\Domain\Security\ExactMatchHmac;
use App\Modules\Client\Domain\Security\SensitiveDataProtector;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Persistence\Models\ClientBankAccount;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Credit\Application\Contracts\CreditVoucherGateway;
use App\Modules\Voucher\Application\Contracts\CreditBalanceSnapshotPort;
use App\Modules\Voucher\Application\Contracts\VoucherEligibilityPort;
use App\Modules\Voucher\Application\DTOs\OperationMetadata;
use App\Modules\Voucher\Application\DTOs\VoucherEligibility;
use App\Modules\Voucher\Application\Security\VoucherActorContextFactory;
use App\Modules\Voucher\Application\Services\CounterVoucherService;
use App\Modules\Voucher\Application\Services\ModificationWorkflowService;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\AuthorizationTokenModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\DataChangeRequestModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FakeClientDistributorProfiles;
use Tests\Support\FakeCreditBalanceSnapshot;
use Tests\Support\FakeVoucherCreditGateway;
use Tests\Support\FakeVoucherEligibility;
use Tests\TestCase;

final class VoucherCounterWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('client.hmac_key', 'client-hmac-key-for-tests');
        config()->set('voucher.token_hash_key', 'voucher-token-key-for-tests');
        config()->set('voucher.transaction_hmac_key', 'voucher-transaction-key-for-tests');
        config()->set('voucher.idempotency_hmac_key', 'voucher-idempotency-key-for-tests');
        $this->createM08Projection();
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_complete_counter_modification_release_and_fulfillment_flow_is_atomic_and_idempotent(): void
    {
        [$branch, $cashier, $authority, $client, $bankAccount, $voucher] = $this->context();
        $credit = new FakeVoucherCreditGateway;
        $this->app->instance(CreditVoucherGateway::class, $credit);
        $this->app->instance(CreditBalanceSnapshotPort::class, new FakeCreditBalanceSnapshot);
        $this->app->instance(
            VoucherEligibilityPort::class,
            new FakeVoucherEligibility(new VoucherEligibility($bankAccount->id, 777)),
        );
        $actors = $this->app->make(VoucherActorContextFactory::class);
        $cashierActor = $actors->fromUser($cashier);
        $counter = $this->app->make(CounterVoucherService::class);
        $modifications = $this->app->make(ModificationWorkflowService::class);

        $opened = $counter->open($voucher->id, 1, $cashierActor, $this->metadata('open-voucher'));
        self::assertSame(VoucherStatus::COUNTER_VALIDATION->value, $opened['status']);
        $replayed = $counter->open($voucher->id, 1, $cashierActor, $this->metadata('open-voucher'));
        self::assertSame($opened, $replayed);
        self::assertDatabaseCount('voucher_operation_history', 1);

        $requested = $modifications->request(
            $voucher->id,
            ['given_names'],
            'El nombre presentado no coincide.',
            2,
            $cashierActor,
            $this->metadata('request-change'),
        );
        self::assertSame('PENDIENTE', $requested['status']);
        self::assertSame(VoucherStatus::CORRECTION_PENDING, $voucher->refresh()->status);

        [$authority, $reauthentication] = $this->grantDecisionReauthentication(
            $authority,
            $branch,
            (string) $requested['request_id'],
            $voucher->id,
            $client->id,
            ['given_names'],
            'Documentación revisada.',
        );
        $authorized = $modifications->authorize(
            (string) $requested['request_id'],
            1,
            'Documentación revisada.',
            $reauthentication,
            $authority,
            $actors->fromUser($authority),
            $this->metadata('authorize-change'),
        );
        self::assertSame('AUTORIZADO', $authorized['status']);
        self::assertArrayHasKey('token', $authorized);
        $requestModel = DataChangeRequestModel::query()->findOrFail($requested['request_id']);
        self::assertSame(
            300,
            (int) $requestModel->decided_at->diffInSeconds(
                AuthorizationTokenModel::query()
                    ->where('data_change_request_id', $requestModel->id)
                    ->sole()
                    ->expires_at,
            ),
        );

        $applied = $modifications->apply(
            $requestModel->id,
            (string) $authorized['token'],
            ['given_names' => ['value' => 'Nombre Corregido']],
            3,
            $cashierActor,
            $this->metadata('apply-change'),
        );
        self::assertSame('USADO', $applied['status']);
        self::assertSame('Nombre Corregido', $client->refresh()->given_names);
        self::assertSame(VoucherStatus::COUNTER_VALIDATION, $voucher->refresh()->status);
        self::assertDatabaseCount('voucher_change_history', 1);

        $checks = [
            'identity_verified' => true,
            'address_verified' => true,
            'identification_verified' => true,
            'proof_of_address_verified' => true,
            'bank_account_verified' => true,
        ];
        $released = $counter->release(
            $voucher->id,
            4,
            $checks,
            $cashierActor,
            $this->metadata('release-voucher'),
        );
        self::assertSame(VoucherStatus::RELEASED->value, $released['status']);

        $fulfilled = $counter->fulfill(
            $voucher->id,
            5,
            'TRX-EXTERNA-0001',
            $cashierActor,
            $this->metadata('fulfill-voucher'),
        );
        self::assertSame(VoucherStatus::FULFILLED->value, $fulfilled['status']);
        self::assertSame('15000.00', $fulfilled['credit_line']['available']);
        self::assertSame('15000.00', $credit->fulfilled[$voucher->id]->capital->format());
        self::assertDatabaseCount('voucher_fulfillments', 1);
        self::assertDatabaseCount('credit_line_movements', 0);
        self::assertDatabaseHas('voucher_outbox_events', ['event_type' => 'VoucherFulfilled']);
    }

    public function test_other_branch_cannot_open_and_consumed_token_cannot_be_reused(): void
    {
        [$branch, $cashier, $authority, $client, $bankAccount, $voucher] = $this->context();
        $otherCashier = User::factory()->cashier()->create([
            'state' => AccountState::ACTIVE,
        ]);
        $actors = $this->app->make(VoucherActorContextFactory::class);
        $counter = $this->app->make(CounterVoucherService::class);

        try {
            $counter->open(
                $voucher->id,
                1,
                $actors->fromUser($otherCashier),
                $this->metadata('other-branch-open'),
            );
            self::fail('Una cajera de otra sucursal no debe enumerar ni abrir el vale.');
        } catch (VoucherDomainException $exception) {
            self::assertSame('VOUCHER_NOT_FOUND', $exception->errorCode());
        }

        $this->app->instance(
            VoucherEligibilityPort::class,
            new FakeVoucherEligibility(new VoucherEligibility($bankAccount->id, 777)),
        );
        $cashierActor = $actors->fromUser($cashier);
        $counter->open($voucher->id, 1, $cashierActor, $this->metadata('valid-open'));
        $workflow = $this->app->make(ModificationWorkflowService::class);
        $requested = $workflow->request(
            $voucher->id,
            ['given_names'],
            'El nombre presentado no coincide.',
            2,
            $cashierActor,
            $this->metadata('valid-request'),
        );
        [$authority, $reauthentication] = $this->grantDecisionReauthentication(
            $authority,
            $branch,
            (string) $requested['request_id'],
            $voucher->id,
            $client->id,
            ['given_names'],
            'Documentación revisada.',
        );
        $authorized = $workflow->authorize(
            (string) $requested['request_id'],
            1,
            'Documentación revisada.',
            $reauthentication,
            $authority,
            $actors->fromUser($authority),
            $this->metadata('valid-authorize'),
        );
        $workflow->apply(
            (string) $requested['request_id'],
            (string) $authorized['token'],
            ['given_names' => ['value' => 'Nombre Corregido']],
            3,
            $cashierActor,
            $this->metadata('first-token-use'),
        );

        $this->expectException(VoucherDomainException::class);
        $workflow->apply(
            (string) $requested['request_id'],
            (string) $authorized['token'],
            ['given_names' => ['value' => 'Otro Nombre']],
            4,
            $cashierActor,
            $this->metadata('second-token-use'),
        );
    }

    public function test_api_requires_operation_headers_and_opens_only_the_scoped_voucher(): void
    {
        [, $cashier, , , , $voucher] = $this->context();
        $this->actingAs($cashier, 'sanctum');

        $this->postJson("/api/v1/vouchers/{$voucher->id}/open-at-counter", [
            'lock_version' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'error' => [
                    'fields' => [
                        'idempotency_key',
                        'request_id',
                    ],
                ],
            ]);

        $this->withHeaders([
            'Idempotency-Key' => 'api-open-voucher-idempotency',
            'X-Request-Id' => (string) Str::uuid(),
        ])->postJson("/api/v1/vouchers/{$voucher->id}/open-at-counter", [
            'lock_version' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', VoucherStatus::COUNTER_VALIDATION->value)
            ->assertJsonPath('data.lock_version', 2);
    }

    /** @return array{Branch, User, User, Client, ClientBankAccount, VoucherModel} */
    private function context(): array
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->create([
            'branch_id' => $branch->id,
            'state' => AccountState::ACTIVE,
        ]);
        $authority = User::factory()->coordinator()->create([
            'branch_id' => $branch->id,
            'state' => AccountState::ACTIVE,
        ]);
        $protector = $this->app->make(SensitiveDataProtector::class);
        $hmac = $this->app->make(ExactMatchHmac::class);
        $client = new Client;
        $client->forceFill([
            'given_names' => 'Nombre Original',
            'surnames' => 'Apellido Prueba',
            'curp_ciphertext' => $protector->encrypt('ABCD010101HDFRRN09'),
            'curp_hmac' => $hmac->make('ABCD010101HDFRRN09'),
            'curp_last4' => 'RN09',
            'created_by' => $cashier->id,
            'registration_operation_id' => 'registration-'.Str::uuid(),
            'lock_version' => 1,
        ])->save();
        $distributorId = (string) Str::uuid();
        $distributorUser = User::factory()->distributor()->create([
            'branch_id' => $branch->id,
            'state' => AccountState::ACTIVE,
        ]);
        DB::table('distributors')->insert([
            'id' => $distributorId,
            'distributor_number' => 'D-'.Str::upper(Str::random(8)),
            'onboarding_application_id' => (string) Str::uuid(),
            'user_id' => $distributorUser->public_id,
            'branch_id' => $branch->public_id,
            'status' => 'ACTIVE',
            'activated_at' => now('UTC'),
            'activation_operation_id' => (string) Str::uuid(),
            'lock_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('distributor_access_links')->insert([
            'public_id' => (string) Str::uuid(),
            'user_id' => $distributorUser->id,
            'external_request_id' => 'request-'.Str::uuid(),
            'external_distributor_id' => $distributorId,
            'branch_id' => $branch->id,
            'coordinator_user_id' => $authority->id,
            'authorized_by' => $authority->id,
            'initial_credit_line' => '30000.00',
            'authorized_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
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
        $bank = new ClientBankAccount;
        $bank->forceFill([
            'client_id' => $client->id,
            'account_ciphertext' => $protector->encrypt('646180157034567890'),
            'account_hmac' => $hmac->make('646180157034567890'),
            'account_last4' => '7890',
            'effective_from' => now('UTC'),
            'created_by' => $cashier->id,
            'active_slot' => true,
            'created_at' => now('UTC'),
        ])->save();
        $profiles = new FakeClientDistributorProfiles;
        $profiles->add(new DistributorProfile(
            $distributorId,
            'D-001',
            $branch->id,
            $branch->public_id,
            $branch->name,
        ));
        $this->app->instance(DistributorProfilePort::class, $profiles);

        $productId = (string) Str::uuid();
        $productVersionId = (string) Str::uuid();
        $productInternalId = DB::table('products')->insertGetId([
            'public_id' => $productId,
            'status' => 'PUBLISHED',
            'created_by' => $cashier->id,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('product_versions')->insert([
            'public_id' => $productVersionId,
            'product_id' => $productInternalId,
            'version_number' => 1,
            'amount' => '15000.0000',
            'loan_commission_rate' => '0.1000',
            'interest_rate_per_fortnight' => '0.0100',
            'insurance_amount' => '100.0000',
            'fortnight_count' => 8,
            'status' => 'PUBLISHED',
            'effective_from' => now('UTC')->subMinute(),
            'created_by' => $cashier->id,
            'published_by' => $cashier->id,
            'published_at' => now('UTC'),
            'reason' => 'Configuración sintética de M09.',
            'lock_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $categoryId = (string) Str::uuid();
        $categoryVersionId = (string) Str::uuid();
        $categoryInternalId = DB::table('categories')->insertGetId([
            'public_id' => $categoryId,
            'status' => 'PUBLISHED',
            'created_by' => $cashier->id,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('category_versions')->insert([
            'public_id' => $categoryVersionId,
            'category_id' => $categoryInternalId,
            'version_number' => 1,
            'name' => 'Categoría de prueba',
            'description' => 'Datos sintéticos.',
            'distributor_profit_rate' => '0.1000',
            'status' => 'PUBLISHED',
            'effective_from' => now('UTC')->subMinute(),
            'created_by' => $cashier->id,
            'published_by' => $cashier->id,
            'published_at' => now('UTC'),
            'reason' => 'Configuración sintética de M09.',
            'lock_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $creditLineId = DB::table('credit_lines')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'distributor_id' => $distributorUser->id,
            'total_authorized' => '30000.0000',
            'used_balance' => '0.0000',
            'available_balance' => '30000.0000',
            'recovered_capital_total' => '0.0000',
            'lock_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $voucher = new VoucherModel;
        $voucher->forceFill([
            'folio' => 'PV-000001',
            'type' => 'PREVALE',
            'status' => VoucherStatus::GENERATED,
            'branch_id' => $branch->id,
            'client_id' => $client->id,
            'distributor_id' => $distributorId,
            'distributor_user_id' => $distributorUser->id,
            'product_id' => $productId,
            'product_version_id' => $productVersionId,
            'category_id' => $categoryId,
            'category_version_id' => $categoryVersionId,
            'credit_line_id' => $creditLineId,
            'capital_amount' => '15000.0000',
            'credit_available_snapshot' => '30000.0000',
            'financial_snapshot' => [
                'capital' => '15000.00',
                'commission' => '1000.00',
                'interest' => '500.00',
                'insurance' => '100.00',
                'profit' => '1600.00',
            ],
            'client_name_snapshot' => 'Nombre Original Apellido Prueba',
            'client_name_normalized' => 'nombre original apellido prueba',
            'generated_by' => $distributorUser->id,
            'generated_at' => now('UTC'),
            'lock_version' => 1,
        ])->save();

        return [$branch, $cashier, $authority, $client, $bank, $voucher];
    }

    /**
     * @param  list<string>  $fields
     * @return array{User, string}
     */
    private function grantDecisionReauthentication(
        User $authority,
        Branch $branch,
        string $requestId,
        string $voucherId,
        string $clientId,
        array $fields,
        string $reason,
    ): array {
        $session = AuthSession::query()->create([
            'user_id' => $authority->id,
            'application' => 'administrativa',
            'device_id' => 'authority-device',
            'ip_address' => '127.0.0.1',
            'context_version' => $authority->context_version,
            'last_activity_at' => now('UTC'),
            'expires_at' => now('UTC')->addHours(8),
            'state' => 'ACTIVE',
        ]);
        $createdToken = $authority->createToken('administrativa', ['*'], now('UTC')->addMinutes(10));
        $createdToken->accessToken->forceFill([
            'auth_session_id' => $session->id,
            'context_version' => $authority->context_version,
        ])->save();
        $authority->withAccessToken($createdToken->accessToken);
        $plain = Str::random(64);
        $binding = new AuthorizationBinding(
            action: CriticalAction::VOUCHER_MODIFICATION_DECIDE,
            resourceType: DataChangeRequestModel::class,
            resourceId: $requestId,
            branchId: $branch->public_id,
            parameters: [
                'voucher_id' => $voucherId,
                'client_id' => $clientId,
                'fields' => $fields,
            ],
            reason: $reason,
        );
        $issuedAt = now('UTC');
        ReauthAuthorization::query()->create([
            'user_id' => $authority->id,
            'auth_session_id' => $session->id,
            'requester_user_id' => $authority->id,
            'method' => 'PASSWORD_TOTP',
            'action' => CriticalAction::VOUCHER_MODIFICATION_DECIDE->value,
            'resource_type' => DataChangeRequestModel::class,
            'record_id' => $requestId,
            'branch_id' => $branch->public_id,
            'parameters_hash' => $binding->parametersHash(),
            'context_version' => $authority->context_version,
            'reason' => $reason,
            'token_hash' => hash('sha256', $plain),
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->clone()->addMinutes(5),
        ]);

        return [$authority, $plain];
    }

    private function metadata(string $key): OperationMetadata
    {
        return new OperationMetadata(
            requestId: (string) Str::uuid(),
            idempotencyKey: $key.'-idempotency-key',
            ip: '127.0.0.1',
            userAgent: 'M09 test agent',
        );
    }

    private function createM08Projection(): void
    {
        if (Schema::hasTable('vouchers')) {
            return;
        }
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('folio')->unique();
            $table->string('type', 40);
            $table->string('status', 32);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_id');
            $table->uuid('distributor_id');
            $table->unsignedBigInteger('distributor_user_id');
            $table->uuid('product_id');
            $table->uuid('product_version_id');
            $table->decimal('capital_amount', 19, 4);
            $table->json('financial_snapshot');
            $table->string('client_name_snapshot');
            $table->string('client_name_normalized');
            $table->timestampTz('generated_at');
            $table->foreignId('opened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('rejected_at')->nullable();
            $table->string('rejection_reason_code')->nullable();
            $table->string('rejection_description', 500)->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('fulfilled_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
        });
    }
}
