<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Permission;
use App\Modules\DistributorOnboarding\Application\Applications\CreateApplication;
use App\Modules\DistributorOnboarding\Application\Applications\CreateApplicationCommand;
use App\Modules\DistributorOnboarding\Application\Applications\SubmitApplication;
use App\Modules\DistributorOnboarding\Application\Applications\SubmitApplicationCommand;
use App\Modules\DistributorOnboarding\Application\Applications\UpdateCapture;
use App\Modules\DistributorOnboarding\Application\Applications\UpdateCaptureCommand;
use App\Modules\DistributorOnboarding\Application\Authorizations\RecordManagerDecision;
use App\Modules\DistributorOnboarding\Application\Authorizations\RecordManagerDecisionCommand;
use App\Modules\DistributorOnboarding\Application\Evaluations\RecordCoordinatorDecision;
use App\Modules\DistributorOnboarding\Application\Evaluations\RecordCoordinatorDecisionCommand;
use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Security\ActorContextFactory;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;
use App\Modules\DistributorOnboarding\Application\VerificationAssignments\AssignVerifier;
use App\Modules\DistributorOnboarding\Application\VerificationAssignments\AssignVerifierCommand;
use App\Modules\DistributorOnboarding\Application\Visits\CompleteVisit;
use App\Modules\DistributorOnboarding\Application\Visits\CompleteVisitCommand;
use App\Modules\DistributorOnboarding\Application\Visits\StartVisit;
use App\Modules\DistributorOnboarding\Application\Visits\StartVisitCommand;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Domain\Contracts\AccountPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\AccountProvisionResult;
use App\Modules\DistributorOnboarding\Domain\Contracts\ConfigurationPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\CreditLinePort;
use App\Modules\DistributorOnboarding\Domain\Contracts\CreditLineProvisionResult;
use App\Modules\DistributorOnboarding\Domain\Contracts\DistributorPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\DistributorProvisionResult;
use App\Modules\DistributorOnboarding\Domain\Contracts\ExpedientRequirementsPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\OrganizationPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\ReauthenticationPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\ResponsibleContext;
use App\Modules\DistributorOnboarding\Domain\Decisions\CoordinatorDecision;
use App\Modules\DistributorOnboarding\Domain\Decisions\ManagerDecision;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Domain\Verification\VisitResult;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationActivationRecord;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationAuthorization;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use App\Modules\DistributorOnboarding\Persistence\Models\VerificationVisit;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DistributorOnboardingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $coordinator;

    private User $verifier;

    private ActorContextFactory $contexts;

    private FakeAccountPort $accounts;

    private FakeConfigurationPort $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessFoundationSeeder::class);
        $this->branch = Branch::factory()->create();
        $this->coordinator = User::factory()->coordinator()->create(['branch_id' => $this->branch->id]);
        $this->verifier = User::factory()->verifier()->create(['branch_id' => $this->branch->id]);
        $this->grantCaptureAndAssignmentPermissions();
        $this->contexts = app(ActorContextFactory::class);
        $this->accounts = new FakeAccountPort;
        $this->configuration = new FakeConfigurationPort;

        $this->app->instance(OrganizationPort::class, new FakeOrganizationPort(
            $this->branch->id,
            $this->coordinator->id,
            $this->verifier->id,
        ));
        $this->app->instance(ExpedientRequirementsPort::class, new CompleteExpedientRequirements);
        $this->app->instance(ReauthenticationPort::class, new SuccessfulReauthentication);
        $this->app->instance(AccountPort::class, $this->accounts);
        $this->app->instance(DistributorPort::class, new FakeDistributorPort);
        $this->app->instance(ConfigurationPort::class, $this->configuration);
        $this->app->instance(CreditLinePort::class, new FakeCreditLinePort);
    }

    public function test_favorable_flow_activates_exactly_once_and_replay_returns_the_same_result(): void
    {
        $coordinator = $this->contexts->fromUser($this->coordinator);
        $application = app(CreateApplication::class)->execute(new CreateApplicationCommand(
            'aspirante@example.test',
            'Aspirante Sintética',
            $coordinator,
            $this->metadata('create'),
        ));
        $application = app(UpdateCapture::class)->execute(new UpdateCaptureCommand(
            $application->public_id,
            $application->lock_version,
            null,
            null,
            [
                'first_name' => 'Alicia',
                'paternal_surname' => 'Prueba',
                'maternal_surname' => 'Sintética',
            ],
            $coordinator,
            $this->metadata('capture'),
        ));
        $application = app(SubmitApplication::class)->execute(new SubmitApplicationCommand(
            $application->public_id,
            $application->lock_version,
            $coordinator,
            $this->metadata('submit'),
        ));
        $application = app(AssignVerifier::class)->execute(new AssignVerifierCommand(
            $application->public_id,
            $this->verifier->public_id,
            $application->lock_version,
            $coordinator,
            $this->metadata('assign'),
        ));

        $verifier = $this->contexts->fromUser($this->verifier);
        $visit = app(StartVisit::class)->execute(new StartVisitCommand(
            $application->public_id,
            $application->lock_version,
            null,
            $verifier,
            $this->metadata('visit-start'),
        ));
        $application = $application->refresh();
        app(CompleteVisit::class)->execute(new CompleteVisitCommand(
            $application->public_id,
            $visit->public_id,
            $application->lock_version,
            VisitResult::FAVORABLE,
            'La visita física fue favorable.',
            $verifier,
            $this->metadata('visit-complete'),
        ));

        $application = $application->refresh();
        $application = app(RecordCoordinatorDecision::class)->execute(new RecordCoordinatorDecisionCommand(
            $application->public_id,
            $application->lock_version,
            CoordinatorDecision::MEETS_REQUIREMENTS,
            'Cumple con la revisión coordinada.',
            $coordinator,
            $this->metadata('evaluation'),
        ));

        $managerUser = User::factory()->generalManager()->create();
        $manager = $this->contexts->fromUser($managerUser);
        $command = new RecordManagerDecisionCommand(
            $application->public_id,
            $application->lock_version,
            ManagerDecision::APPROVE,
            '15000.0000',
            'Autorización manual basada en el expediente.',
            'reauthentication-token',
            $manager,
            $this->metadata('manager-decision'),
        );
        $active = app(RecordManagerDecision::class)->execute($command);
        $replayed = app(RecordManagerDecision::class)->execute($command);

        self::assertSame(ApplicationStatus::ACTIVE, $active->status);
        self::assertSame($active->public_id, $replayed->public_id);
        self::assertSame(1, ApplicationAuthorization::query()->count());
        self::assertSame(1, ApplicationActivationRecord::query()->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-001')->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-002')->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-003')->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-005')->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-007')->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-008')->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-010')->count());
        self::assertSame(1, $this->accounts->provisionCalls);
        self::assertSame(['15000.0000'], $this->configuration->validatedAmounts);
        self::assertDatabaseMissing('application_activation_records', ['distributor_number' => null]);
    }

    public function test_unfavorable_visit_is_terminal_and_never_creates_a_manager_decision(): void
    {
        [$application, $visit, $verifier] = $this->applicationWithStartedVisit();

        app(CompleteVisit::class)->execute(new CompleteVisitCommand(
            $application->public_id,
            $visit->public_id,
            $application->lock_version,
            VisitResult::UNFAVORABLE,
            'La verificación física fue desfavorable.',
            $verifier,
            $this->metadata('unfavorable-visit'),
        ));

        self::assertSame(ApplicationStatus::TERMINATED_UNFAVORABLE, $application->refresh()->status);
        self::assertSame(0, ApplicationAuthorization::query()->count());
        self::assertSame(0, ApplicationActivationRecord::query()->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-003')->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-006')->count());
        self::assertSame(0, DB::table('outbox_events')->where('type', 'EV-007')->count());
    }

    public function test_failed_activation_stays_pending_and_same_decision_can_be_retried(): void
    {
        $creditLines = new FailsOnceCreditLinePort;
        $this->app->instance(CreditLinePort::class, $creditLines);
        [$application, $manager] = $this->applicationReadyForManager();
        $command = new RecordManagerDecisionCommand(
            $application->public_id,
            $application->lock_version,
            ManagerDecision::APPROVE,
            '15000.0000',
            'Autorización para comprobar el reintento.',
            'reauthentication-token',
            $manager,
            $this->metadata('manager-retry'),
        );

        try {
            app(RecordManagerDecision::class)->execute($command);
            self::fail('The first activation was expected to fail.');
        } catch (OnboardingDomainException $exception) {
            self::assertSame('APPLICATION_CREDIT_LINE_PROVISION_FAILED', $exception->errorCode());
        }

        self::assertSame(ApplicationStatus::MANAGER_AUTHORIZATION, $application->refresh()->status);
        self::assertSame(1, ApplicationAuthorization::query()->count());
        self::assertSame(0, ApplicationActivationRecord::query()->count());
        self::assertSame(0, DB::table('outbox_events')->whereIn('type', ['EV-008', 'EV-010'])->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'M04_ACTIVATION_FAILED')->count());

        $active = app(RecordManagerDecision::class)->execute($command);

        self::assertSame(ApplicationStatus::ACTIVE, $active->status);
        self::assertSame(1, ApplicationActivationRecord::query()->count());
        self::assertSame(2, $creditLines->calls);
        self::assertSame(1, DB::table('outbox_events')->where('type', 'M04_ACTIVATION_RETRIED')->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-008')->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-010')->count());
    }

    public function test_manager_rejection_is_terminal_without_operational_records(): void
    {
        [$application, $manager] = $this->applicationReadyForManager();

        $rejected = app(RecordManagerDecision::class)->execute(new RecordManagerDecisionCommand(
            $application->public_id,
            $application->lock_version,
            ManagerDecision::REJECT,
            null,
            'El expediente no se autoriza.',
            null,
            $manager,
            $this->metadata('manager-rejection'),
        ));

        self::assertSame(ApplicationStatus::REJECTED, $rejected->status);
        self::assertSame(1, ApplicationAuthorization::query()->count());
        self::assertSame(0, ApplicationActivationRecord::query()->count());
        self::assertSame(0, $this->accounts->provisionCalls);
        self::assertSame(1, DB::table('outbox_events')->where('type', 'EV-009')->count());
        self::assertSame(0, DB::table('outbox_events')->whereIn('type', ['EV-008', 'EV-010'])->count());
    }

    /** @return array{DistributorApplication, ActorContext} */
    private function applicationReadyForManager(): array
    {
        [$application, $visit, $verifier] = $this->applicationWithStartedVisit();
        app(CompleteVisit::class)->execute(new CompleteVisitCommand(
            $application->public_id,
            $visit->public_id,
            $application->lock_version,
            VisitResult::FAVORABLE,
            'La visita física fue favorable.',
            $verifier,
            $this->metadata('retry-visit-complete'),
        ));
        $application = $application->refresh();
        $coordinator = $this->contexts->fromUser($this->coordinator);
        $application = app(RecordCoordinatorDecision::class)->execute(new RecordCoordinatorDecisionCommand(
            $application->public_id,
            $application->lock_version,
            CoordinatorDecision::MEETS_REQUIREMENTS,
            'Cumple con la revisión coordinada.',
            $coordinator,
            $this->metadata('retry-evaluation'),
        ));
        $manager = $this->contexts->fromUser(User::factory()->generalManager()->create());

        return [$application, $manager];
    }

    /** @return array{DistributorApplication, VerificationVisit, ActorContext} */
    private function applicationWithStartedVisit(): array
    {
        $coordinator = $this->contexts->fromUser($this->coordinator);
        $application = app(CreateApplication::class)->execute(new CreateApplicationCommand(
            'visit@example.test',
            'Visita Sintética',
            $coordinator,
            $this->metadata('visit-create'),
        ));
        $application = app(UpdateCapture::class)->execute(new UpdateCaptureCommand(
            $application->public_id,
            $application->lock_version,
            null,
            null,
            ['first_name' => 'Elena', 'paternal_surname' => 'Prueba'],
            $coordinator,
            $this->metadata('visit-capture'),
        ));
        $application = app(SubmitApplication::class)->execute(new SubmitApplicationCommand(
            $application->public_id,
            $application->lock_version,
            $coordinator,
            $this->metadata('visit-submit'),
        ));
        $application = app(AssignVerifier::class)->execute(new AssignVerifierCommand(
            $application->public_id,
            $this->verifier->public_id,
            $application->lock_version,
            $coordinator,
            $this->metadata('visit-assign'),
        ));
        $verifier = $this->contexts->fromUser($this->verifier);
        $visit = app(StartVisit::class)->execute(new StartVisitCommand(
            $application->public_id,
            $application->lock_version,
            null,
            $verifier,
            $this->metadata('visit-begin'),
        ));

        return [$application->refresh(), $visit, $verifier];
    }

    private function grantCaptureAndAssignmentPermissions(): void
    {
        $permissionIds = Permission::query()->whereIn('code', [
            PermissionCode::ONBOARDING_APPLICATIONS_CREATE->value,
            PermissionCode::ONBOARDING_APPLICATIONS_UPDATE_CAPTURE->value,
            PermissionCode::ONBOARDING_APPLICATIONS_SUBMIT->value,
            PermissionCode::ONBOARDING_VERIFICATIONS_ASSIGN->value,
        ])->pluck('id');
        $this->coordinator->role()->firstOrFail()->permissions()->syncWithoutDetaching($permissionIds);
    }

    private function metadata(string $key): OperationMetadata
    {
        return new OperationMetadata($key, (string) Str::uuid());
    }
}

final readonly class FakeOrganizationPort implements OrganizationPort
{
    public function __construct(
        private int $branchId,
        private int $coordinatorId,
        private int $verifierId,
    ) {}

    public function resolveCreationContext(ActorContext $actor): ResponsibleContext
    {
        return new ResponsibleContext($this->branchId, $this->coordinatorId);
    }

    public function assertResponsibleCoordinator(int $coordinatorUserId, int $branchId): void
    {
        if ($this->coordinatorId !== $coordinatorUserId || $this->branchId !== $branchId) {
            throw new \LogicException('Unexpected coordinator context.');
        }
    }

    public function assertVerifier(int $verifierUserId, int $branchId): void
    {
        if ($this->verifierId !== $verifierUserId || $this->branchId !== $branchId) {
            throw new \LogicException('Unexpected verifier context.');
        }
    }

    public function createDistributorAssignment(string $distributorId, int $coordinatorUserId, int $branchId, string $operationKey): string
    {
        $this->assertResponsibleCoordinator($coordinatorUserId, $branchId);

        return '10000000-0000-4000-8000-000000000003';
    }
}

final class CompleteExpedientRequirements implements ExpedientRequirementsPort
{
    public int $calls = 0;

    public function assertComplete(DistributorApplication $application): void
    {
        $this->calls++;
    }
}

final class SuccessfulReauthentication implements ReauthenticationPort
{
    public int $calls = 0;

    public function consume(int $userId, string $applicationPublicId, string $plainToken): void
    {
        $this->calls++;
    }
}

final class FakeAccountPort implements AccountPort
{
    public int $provisionCalls = 0;

    public int $availabilityCalls = 0;

    public function assertEmailAvailable(string $normalizedEmail): void
    {
        $this->availabilityCalls++;
    }

    public function provisionDistributor(string $normalizedEmail, string $name, int $branchId, string $operationKey): AccountProvisionResult
    {
        $this->provisionCalls++;

        return new AccountProvisionResult('10000000-0000-4000-8000-000000000002');
    }
}

final class FakeDistributorPort implements DistributorPort
{
    public function provision(string $applicationPublicId, string $name, int $branchId, string $operationKey): DistributorProvisionResult
    {
        return new DistributorProvisionResult(
            '10000000-0000-4000-8000-000000000001',
            'DISTRIBUTOR-TEST-001',
        );
    }
}

final class FakeConfigurationPort implements ConfigurationPort
{
    /** @var list<string> */
    public array $validatedAmounts = [];

    public function assertInitialCreditLineAllowed(string $amount): void
    {
        $this->validatedAmounts[] = $amount;
    }

    public function firstVoucherTolerance(): string
    {
        return '0.0000';
    }
}

final class FakeCreditLinePort implements CreditLinePort
{
    public function openInitialLine(string $distributorId, string $amount, string $tolerance, string $operationKey): CreditLineProvisionResult
    {
        return new CreditLineProvisionResult(
            '10000000-0000-4000-8000-000000000004',
            '10000000-0000-4000-8000-000000000005',
            '10000000-0000-4000-8000-000000000006',
        );
    }
}

final class FailsOnceCreditLinePort implements CreditLinePort
{
    public int $calls = 0;

    public function openInitialLine(string $distributorId, string $amount, string $tolerance, string $operationKey): CreditLineProvisionResult
    {
        $this->calls++;
        if ($this->calls === 1) {
            throw OnboardingDomainException::integrationUnavailable('APPLICATION_CREDIT_LINE_PROVISION_FAILED');
        }

        return new CreditLineProvisionResult(
            '20000000-0000-4000-8000-000000000004',
            '20000000-0000-4000-8000-000000000005',
            '20000000-0000-4000-8000-000000000006',
        );
    }
}
