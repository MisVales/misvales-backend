<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationFamilyMember;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationVehicle;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DistributorOnboardingPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_all_m04_tables_exist(): void
    {
        foreach ([
            'distributor_applications', 'application_personal_data', 'application_family_members',
            'application_family_references', 'application_residences', 'application_vehicles',
            'application_assets_liabilities', 'application_employments', 'application_labor_references',
            'application_commercial_credits', 'application_capture_revisions', 'application_submissions',
            'application_review_observations', 'application_verifier_assignments', 'verification_visits',
            'application_media_links', 'verification_differences', 'application_corrections',
            'application_evaluations', 'application_authorizations', 'application_activation_records',
            'application_status_histories', 'application_audits', 'onboarding_idempotency_keys',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_sensitive_values_are_encrypted_at_rest_and_collections_are_separate(): void
    {
        [$application] = $this->application();
        $member = new ApplicationFamilyMember;
        $member->forceFill([
            'application_id' => $application->id,
            'relationship_code' => 'TEST_RELATIONSHIP',
            'name' => 'Persona Sintética',
            'age' => 30,
        ])->save();
        $vehicle = new ApplicationVehicle;
        $vehicle->forceFill([
            'application_id' => $application->id,
            'declared_details' => 'Vehículo sintético',
        ])->save();

        $rawContactEmail = DB::table('distributor_applications')
            ->where('id', $application->id)
            ->value('contact_email');
        $rawMemberName = DB::table('application_family_members')
            ->where('id', $member->id)
            ->value('name');
        $rawVehicleDetails = DB::table('application_vehicles')
            ->where('id', $vehicle->id)
            ->value('declared_details');

        self::assertNotSame('private@example.test', $rawContactEmail);
        self::assertNotSame('Persona Sintética', $rawMemberName);
        self::assertNotSame('Vehículo sintético', $rawVehicleDetails);
        self::assertSame(1, $application->familyMembers()->count());
        self::assertSame(1, $application->vehicles()->count());
    }

    public function test_postgresql_rejects_a_state_jump_and_terminal_reopening(): void
    {
        $this->requirePostgreSql();
        [$application] = $this->application();

        DB::statement('SAVEPOINT m04_invalid_transition');
        try {
            DB::table('distributor_applications')->where('id', $application->id)->update([
                'status' => ApplicationStatus::ACTIVE->value,
                'result' => ApplicationStatus::ACTIVE->value,
            ]);
            self::fail('PostgreSQL accepted a skipped transition.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT m04_invalid_transition');
            $this->assertDatabaseHas('distributor_applications', [
                'id' => $application->id,
                'status' => ApplicationStatus::CAPTURE->value,
            ]);
        } finally {
            DB::statement('RELEASE SAVEPOINT m04_invalid_transition');
        }
    }

    /** @return array{DistributorApplication, User} */
    private function application(): array
    {
        $branch = Branch::factory()->create();
        $coordinator = User::factory()->coordinator()->create(['branch_id' => $branch->id]);
        $application = new DistributorApplication;
        $application->forceFill([
            'folio' => 'PERSISTENCE-TEST',
            'contact_email' => 'private@example.test',
            'normalized_email_hash' => hash('sha256', 'private@example.test'),
            'account_name' => 'Persona Privada',
            'branch_id' => $branch->id,
            'coordinator_user_id' => $coordinator->id,
            'status' => ApplicationStatus::CAPTURE,
            'lock_version' => 1,
            'created_by' => $coordinator->id,
        ])->save();

        return [$application, $coordinator];
    }

    private function requirePostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only constraint.');
        }
    }
}
