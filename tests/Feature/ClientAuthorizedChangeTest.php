<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Client\Application\Contracts\AuthorizedChangePort;
use App\Modules\Client\Application\Contracts\CashierVoucherAccessPort;
use App\Modules\Client\Application\Contracts\DistributorProfile;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Contracts\DocumentReferencePort;
use App\Modules\Client\Application\Contracts\ResolveClientForCashierVerification;
use App\Modules\Client\Application\Security\ClientActorContextFactory;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeAuthorizedClientChanges;
use Tests\Support\FakeCashierVoucherAccess;
use Tests\Support\FakeClientDistributorProfiles;
use Tests\Support\FakeClientDocuments;
use Tests\TestCase;

final class ClientAuthorizedChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_account_change_requires_m09_contract_and_preserves_history(): void
    {
        $this->seed(AccessFoundationSeeder::class);
        $branch = Branch::factory()->create();
        $distributor = User::factory()->distributor()->create(['branch_id' => $branch->id]);
        $profiles = new FakeClientDistributorProfiles;
        $profiles->addForUser($distributor->id, new DistributorProfile(
            '10000000-0000-4000-8000-000000000001',
            'DIST-001',
            $branch->id,
            $branch->public_id,
            $branch->name,
        ));
        $this->app->instance(DistributorProfilePort::class, $profiles);
        $this->app->instance(DocumentReferencePort::class, new FakeClientDocuments);
        Sanctum::actingAs($distributor);
        $created = $this->withHeader('Idempotency-Key', 'authorized-change-client')
            ->postJson('/api/v1/clients', [
                'given_names' => 'Cliente',
                'surnames' => 'Cambio',
                'curp' => 'ABCD900101HDFRRN09',
                'address' => [
                    'street' => 'Calle Uno',
                    'exterior_number' => '10',
                    'interior_number' => null,
                    'neighborhood' => 'Centro',
                    'postal_code' => '64000',
                    'municipality' => 'Monterrey',
                    'city' => 'Monterrey',
                    'state' => 'Nuevo León',
                ],
                'official_identification_media_id' => '30000000-0000-4000-8000-000000000001',
                'address_proof_media_id' => '30000000-0000-4000-8000-000000000002',
                'bank_account' => '012345678901234567',
            ])
            ->assertCreated();
        $clientId = (string) $created->json('data.id');

        $cashier = User::factory()->cashier()->create(['branch_id' => $branch->id]);
        $authorization = new FakeAuthorizedClientChanges;
        $this->app->instance(AuthorizedChangePort::class, $authorization);
        Sanctum::actingAs($cashier);
        $change = [
            'authorization_id' => '70000000-0000-4000-8000-000000000001',
            'operation_id' => '70000000-0000-4000-8000-000000000002',
            'bank_account' => '987654321098765432',
            'reason' => 'Diferencia confirmada por la cajera.',
            'lock_version' => 1,
        ];
        $this->postJson('/api/v1/clients/'.$clientId.'/bank-accounts', $change)->assertOk()
            ->assertJsonPath('data.account_masked', '********5432');
        $this->postJson('/api/v1/clients/'.$clientId.'/bank-accounts', $change)->assertOk()
            ->assertJsonPath('data.account_masked', '********5432');

        self::assertSame(1, $authorization->consumed);
        self::assertSame(2, DB::table('client_bank_accounts')->where('client_id', $clientId)->count());
        self::assertSame(1, DB::table('client_bank_accounts')->where('client_id', $clientId)->whereNull('active_slot')->count());
        self::assertSame(1, DB::table('client_change_history')->where('client_id', $clientId)->count());
        self::assertNotSame(
            '987654321098765432',
            DB::table('client_bank_accounts')->where('client_id', $clientId)->where('active_slot', true)->value('account_ciphertext'),
        );
        self::assertSame(1, DB::table('outbox_events')->where('type', 'ClientBankAccountChanged')->count());

        $voucherAccess = new FakeCashierVoucherAccess;
        $this->app->instance(CashierVoucherAccessPort::class, $voucherAccess);
        $verification = app(ResolveClientForCashierVerification::class)->handle(
            $clientId,
            '80000000-0000-4000-8000-000000000001',
            app(ClientActorContextFactory::class)->fromUser($cashier),
            '80000000-0000-4000-8000-000000000002',
        );
        self::assertSame('987654321098765432', $verification->bankAccount);
        self::assertSame(2, count($verification->documents));
        self::assertSame(1, $voucherAccess->assertions);
        self::assertSame(1, DB::table('client_audits')->where('event_type', 'CLIENT_CASHIER_VERIFICATION_VIEWED')->count());
    }
}
