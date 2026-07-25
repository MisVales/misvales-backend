<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Client\Application\Contracts\DistributorProfile;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Contracts\DocumentReferencePort;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeClientDistributorProfiles;
use Tests\Support\FakeClientDocuments;
use Tests\TestCase;

final class ClientRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $distributor;

    private Branch $branch;

    private FakeClientDistributorProfiles $profiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessFoundationSeeder::class);
        $this->branch = Branch::factory()->create();
        $this->distributor = User::factory()->distributor()->create(['branch_id' => $this->branch->id]);
        $this->profiles = new FakeClientDistributorProfiles;
        $this->profiles->addForUser($this->distributor->id, new DistributorProfile(
            '10000000-0000-4000-8000-000000000001',
            'DIST-001',
            $this->branch->id,
            $this->branch->public_id,
            $this->branch->name,
        ));
        $this->app->instance(DistributorProfilePort::class, $this->profiles);
        $this->app->instance(DocumentReferencePort::class, new FakeClientDocuments);
    }

    public function test_distributor_registers_one_protected_client_and_exact_replay_returns_it(): void
    {
        Sanctum::actingAs($this->distributor);
        $userCount = User::query()->count();
        $key = 'registration-001';

        $created = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/clients', $this->payload());

        $created->assertCreated()
            ->assertJsonPath('data.display_name', 'Alicia Prueba')
            ->assertJsonPath('data.curp_masked', '**************RN09')
            ->assertJsonPath('data.distributor.id', '10000000-0000-4000-8000-000000000001')
            ->assertJsonPath('data.existing_for_future_vouchers', true)
            ->assertHeader('X-Request-Id');
        $clientId = (string) $created->json('data.id');

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/clients', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.id', $clientId)
            ->assertJsonPath('data.replayed', true);

        self::assertSame($userCount, User::query()->count());
        self::assertSame(1, DB::table('clients')->count());
        self::assertSame(1, DB::table('client_addresses')->count());
        self::assertSame(1, DB::table('client_bank_accounts')->count());
        self::assertSame(2, DB::table('client_documents')->count());
        self::assertSame(1, DB::table('client_distributor_assignments')->count());
        self::assertSame(1, DB::table('client_audits')->where('event_type', 'CLIENT_REGISTERED')->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'ClientRegistered')->count());
        self::assertFalse(Schema::hasColumn('clients', 'user_id'));
        self::assertFalse(Schema::hasColumn('clients', 'delinquent'));
        self::assertFalse(Schema::hasColumn('clients', 'blocked'));
        self::assertNotSame('ABCD900101HDFRRN09', DB::table('clients')->value('curp_ciphertext'));
        self::assertNotSame('Avenida Constitución', DB::table('client_addresses')->value('street_ciphertext'));
        self::assertNotSame('012345678901234567', DB::table('client_bank_accounts')->value('account_ciphertext'));
        $integrationData = json_encode([
            DB::table('outbox_events')->where('type', 'ClientRegistered')->value('payload'),
            DB::table('client_audits')->where('event_type', 'CLIENT_REGISTERED')->get(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('ABCD900101HDFRRN09', $integrationData);
        self::assertStringNotContainsString('Avenida Constitución', $integrationData);
        self::assertStringNotContainsString('012345678901234567', $integrationData);
    }

    public function test_global_curp_address_uniqueness_and_idempotency_conflict_do_not_reveal_owner(): void
    {
        Sanctum::actingAs($this->distributor);
        $this->withHeader('Idempotency-Key', 'registration-original')
            ->postJson('/api/v1/clients', $this->payload())
            ->assertCreated();

        $differentAddress = $this->payload();
        $differentAddress['address']['exterior_number'] = '999';
        $this->withHeader('Idempotency-Key', 'duplicate-curp')
            ->postJson('/api/v1/clients', $differentAddress)
            ->assertConflict()
            ->assertJsonPath('error.code', 'CLIENT_CURP_EXISTS')
            ->assertJsonMissingPath('error.details.client_id');

        $differentCurp = $this->payload();
        $differentCurp['curp'] = 'WXYZ910202MDFRRN08';
        $this->withHeader('Idempotency-Key', 'duplicate-address')
            ->postJson('/api/v1/clients', $differentCurp)
            ->assertConflict()
            ->assertJsonPath('error.code', 'CLIENT_ADDRESS_EXISTS');

        $differentContent = $this->payload();
        $differentContent['given_names'] = 'Nombre Distinto';
        $this->withHeader('Idempotency-Key', 'registration-original')
            ->postJson('/api/v1/clients', $differentContent)
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        self::assertSame(1, DB::table('clients')->count());
    }

    public function test_assignment_authority_mass_assignment_and_out_of_scope_reads_are_denied(): void
    {
        Sanctum::actingAs($this->distributor);
        $payload = $this->payload();
        $payload['distributor_id'] = (string) Str::uuid();
        $payload['branch_id'] = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', 'forbidden-authority')
            ->postJson('/api/v1/clients', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['distributor_id', 'branch_id']]]);

        $created = $this->withHeader('Idempotency-Key', 'visible-owner')
            ->postJson('/api/v1/clients', $this->payload())
            ->assertCreated();
        $clientId = (string) $created->json('data.id');

        $otherBranch = Branch::factory()->create();
        $other = User::factory()->distributor()->create(['branch_id' => $otherBranch->id]);
        $this->profiles->addForUser($other->id, new DistributorProfile(
            '20000000-0000-4000-8000-000000000001',
            'DIST-002',
            $otherBranch->id,
            $otherBranch->public_id,
            $otherBranch->name,
        ));
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/clients/'.$clientId)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'CLIENT_NOT_FOUND_OR_OUT_OF_SCOPE');
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_authentication_is_required_and_administrator_remains_read_only(): void
    {
        $this->getJson('/api/v1/clients')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');

        $administrator = User::factory()->administrator()->create();
        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/clients')->assertOk();
        $this->withHeader('Idempotency-Key', 'administrator-write')
            ->postJson('/api/v1/clients', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'given_names' => 'Alicia',
            'surnames' => 'Prueba',
            'curp' => 'ABCD900101HDFRRN09',
            'rfc' => 'TEST900101AA1',
            'birth_date' => '1990-01-01',
            'birth_place' => 'Monterrey',
            'birth_state' => 'Nuevo León',
            'birth_city' => 'Monterrey',
            'address' => [
                'street' => 'Avenida Constitución',
                'exterior_number' => '101',
                'interior_number' => '2',
                'neighborhood' => 'Centro',
                'postal_code' => '64000',
                'municipality' => 'Monterrey',
                'city' => 'Monterrey',
                'state' => 'Nuevo León',
            ],
            'official_identification_media_id' => '30000000-0000-4000-8000-000000000001',
            'address_proof_media_id' => '30000000-0000-4000-8000-000000000002',
            'bank_account' => '012345678901234567',
        ];
    }
}
