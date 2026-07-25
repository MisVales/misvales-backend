<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Client\Application\Assignments\ApplyAuthorizedAssignment;
use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientAssignmentCommand;
use App\Modules\Client\Application\Contracts\AuthorizedMobilityPort;
use App\Modules\Client\Application\Contracts\ConfirmedVoucherPort;
use App\Modules\Client\Application\Contracts\DistributorProfile;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Contracts\DocumentReferencePort;
use App\Modules\Client\Application\Contracts\RecordClientVoucherReferenceCommand;
use App\Modules\Client\Application\Contracts\ResolveClientForVoucher;
use App\Modules\Client\Application\Contracts\ValidateClientPortfolioForTransfer;
use App\Modules\Client\Application\Contracts\ValidateClientPortfolioForTransferQuery;
use App\Modules\Client\Application\Portfolio\ConfirmZeroPortfolio;
use App\Modules\Client\Application\Portfolio\ConfirmZeroPortfolioCommand;
use App\Modules\Client\Application\Portfolio\RecordVoucherReference;
use App\Modules\Client\Application\Portfolio\SetPortfolioTracking;
use App\Modules\Client\Application\Portfolio\SetPortfolioTrackingCommand;
use App\Modules\Client\Application\Security\ClientActorContextFactory;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeAuthorizedClientMobility;
use Tests\Support\FakeClientDistributorProfiles;
use Tests\Support\FakeClientDocuments;
use Tests\Support\FakeConfirmedClientVoucher;
use Tests\TestCase;

final class ClientPortfolioAndMobilityTest extends TestCase
{
    use RefreshDatabase;

    private User $distributor;

    private Branch $branch;

    private FakeClientDistributorProfiles $profiles;

    private ClientActorContextFactory $contexts;

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
        $this->app->instance(ConfirmedVoucherPort::class, new FakeConfirmedClientVoucher);
        $this->contexts = app(ClientActorContextFactory::class);
    }

    public function test_optional_portfolio_stays_informative_and_transfer_reuses_same_client(): void
    {
        $clientId = $this->registerClient();
        $actor = $this->contexts->fromUser($this->distributor);

        Sanctum::actingAs($this->distributor);
        $this->withHeader('Idempotency-Key', 'payment-disabled')
            ->postJson('/api/v1/clients/'.$clientId.'/portfolio-entries', [
                'entry_type' => 'PAYMENT',
                'amount' => '1.0000',
                'informational_status' => 'PARTIAL',
                'occurred_on' => '2026-07-24',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'PORTFOLIO_TRACKING_DISABLED');

        $setting = ClientPortfolioSetting::query()->where('client_id', $clientId)->firstOrFail();
        app(ConfirmZeroPortfolio::class)->execute(new ConfirmZeroPortfolioCommand(
            $clientId,
            $setting->lock_version,
            '45000000-0000-4000-8000-000000000001',
            '40000000-0000-4000-8000-000000000000',
            $actor,
        ));
        $unused = app(ValidateClientPortfolioForTransfer::class)->handle(
            new ValidateClientPortfolioForTransferQuery(
                $clientId,
                '10000000-0000-4000-8000-000000000001',
                $setting->lock_version,
            ),
        );
        self::assertTrue($unused->allowed);
        self::assertFalse($unused->trackingEnabled);

        app(SetPortfolioTracking::class)->execute(new SetPortfolioTrackingCommand(
            $clientId,
            true,
            $setting->lock_version,
            '40000000-0000-4000-8000-000000000001',
            $actor,
        ));
        app(RecordVoucherReference::class)->handle(new RecordClientVoucherReferenceCommand(
            clientId: $clientId,
            distributorId: '10000000-0000-4000-8000-000000000001',
            voucherId: '50000000-0000-4000-8000-000000000001',
            amount: '100.0000',
            occurredOn: '2026-07-24',
            operationId: '50000000-0000-4000-8000-000000000002',
            requestId: '40000000-0000-4000-8000-000000000002',
            actor: $actor,
        ));

        $this->withHeader('Idempotency-Key', 'payment-partial')
            ->postJson('/api/v1/clients/'.$clientId.'/portfolio-entries', [
                'entry_type' => 'INSTALLMENT',
                'amount' => '40.1234',
                'informational_status' => 'PARTIAL',
                'occurred_on' => '2026-07-24',
                'note' => '<b>Abono informado</b>',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '40.12')
            ->assertJsonPath('data.note', 'Abono informado');
        self::assertNotSame(
            'Abono informado',
            DB::table('client_portfolio_entries')->where('idempotency_key', 'payment-partial')->value('note'),
        );

        $version = ClientPortfolioSetting::query()->where('client_id', $clientId)->value('lock_version');
        $pending = app(ValidateClientPortfolioForTransfer::class)->handle(
            new ValidateClientPortfolioForTransferQuery(
                $clientId,
                '10000000-0000-4000-8000-000000000001',
                (int) $version,
            ),
        );
        self::assertSame('59.8766', $pending->totalBalance);
        self::assertFalse($pending->allowed);
        $selection = app(ResolveClientForVoucher::class)->handle($clientId, $actor);
        self::assertTrue($selection->existingClient);
        self::assertTrue($selection->addressAvailable);

        $this->withHeader('Idempotency-Key', 'payment-over')
            ->postJson('/api/v1/clients/'.$clientId.'/portfolio-entries', [
                'entry_type' => 'PAYMENT',
                'amount' => '60.0000',
                'informational_status' => 'PAID',
                'occurred_on' => '2026-07-24',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PORTFOLIO_ENTRY_INVALID');

        $this->withHeader('Idempotency-Key', 'payment-final')
            ->postJson('/api/v1/clients/'.$clientId.'/portfolio-entries', [
                'entry_type' => 'PAYMENT',
                'amount' => '59.8766',
                'informational_status' => 'PAID',
                'occurred_on' => '2026-07-24',
            ])
            ->assertCreated();

        $destinationBranch = Branch::factory()->create();
        $destinationId = '20000000-0000-4000-8000-000000000001';
        $this->profiles->add(new DistributorProfile(
            $destinationId,
            'DIST-002',
            $destinationBranch->id,
            $destinationBranch->public_id,
            $destinationBranch->name,
        ));
        $mobility = new FakeAuthorizedClientMobility;
        $this->app->instance(AuthorizedMobilityPort::class, $mobility);
        $manager = User::factory()->generalManager()->create();
        $executor = $this->contexts->fromUser($manager);
        $clientVersion = (int) Client::query()->whereKey($clientId)->value('lock_version');
        $portfolioVersion = (int) ClientPortfolioSetting::query()
            ->where('client_id', $clientId)
            ->latest('created_at')
            ->value('lock_version');

        $transfer = new ApplyAuthorizedClientAssignmentCommand(
            mobilityOperationId: '60000000-0000-4000-8000-000000000001',
            clientId: $clientId,
            sourceDistributorId: '10000000-0000-4000-8000-000000000001',
            destinationDistributorId: $destinationId,
            effectiveAt: now()->toIso8601String(),
            reason: 'Transferencia autorizada de prueba.',
            expectedClientVersion: $clientVersion,
            expectedPortfolioVersion: $portfolioVersion,
            requestId: '40000000-0000-4000-8000-000000000003',
            executor: $executor,
        );
        app(ApplyAuthorizedAssignment::class)->handle($transfer);
        app(ApplyAuthorizedAssignment::class)->handle($transfer);
        try {
            app(ApplyAuthorizedAssignment::class)->handle(new ApplyAuthorizedClientAssignmentCommand(
                mobilityOperationId: $transfer->mobilityOperationId,
                clientId: $transfer->clientId,
                sourceDistributorId: $transfer->sourceDistributorId,
                destinationDistributorId: $transfer->destinationDistributorId,
                effectiveAt: $transfer->effectiveAt,
                reason: 'Contenido distinto para la misma operación.',
                expectedClientVersion: $transfer->expectedClientVersion,
                expectedPortfolioVersion: $transfer->expectedPortfolioVersion,
                requestId: '40000000-0000-4000-8000-000000000004',
                executor: $transfer->executor,
            ));
            self::fail('La operación de movilidad aceptó una repetición con contenido distinto.');
        } catch (ClientDomainException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_CONFLICT', $exception->errorCode());
        }

        self::assertSame(1, Client::query()->count());
        self::assertSame(2, ClientDistributorAssignment::query()->where('client_id', $clientId)->count());
        self::assertSame(
            $destinationId,
            ClientDistributorAssignment::query()->where('client_id', $clientId)->where('active_slot', true)->value('distributor_id'),
        );
        self::assertSame(1, $mobility->assertions);
        self::assertSame(1, DB::table('outbox_events')->where('type', 'ClientDistributorAssignmentChanged')->count());
        self::assertDatabaseMissing('clients', ['id' => $clientId, 'lock_version' => $clientVersion]);
    }

    private function registerClient(): string
    {
        Sanctum::actingAs($this->distributor);
        $response = $this->withHeader('Idempotency-Key', 'portfolio-client')
            ->postJson('/api/v1/clients', [
                'given_names' => 'Cliente',
                'surnames' => 'Cartera',
                'curp' => 'ABCD900101HDFRRN09',
                'address' => [
                    'street' => 'Calle Cartera',
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

        return (string) $response->json('data.id');
    }
}
