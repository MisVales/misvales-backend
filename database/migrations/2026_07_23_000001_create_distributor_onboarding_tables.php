<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_applications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('folio', 40)->unique();
            $table->text('contact_email');
            $table->string('normalized_email_hash', 64)->index();
            $table->text('account_name');
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('coordinator_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 40);
            $table->string('result', 40)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();
            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['coordinator_user_id', 'status', 'created_at']);
        });

        Schema::create('application_personal_data', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained('distributor_applications')->restrictOnDelete();
            $table->text('first_name')->nullable();
            $table->text('paternal_surname')->nullable();
            $table->text('maternal_surname')->nullable();
            $table->text('curp')->nullable();
            $table->string('curp_hash', 64)->nullable()->index();
            $table->text('rfc')->nullable();
            $table->string('rfc_hash', 64)->nullable()->index();
            $table->date('birth_date')->nullable();
            $table->text('birth_place')->nullable();
            $table->text('birth_state')->nullable();
            $table->text('birth_city')->nullable();
            $table->text('declared_address')->nullable();
            $table->uuid('official_identification_media_id')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('application_family_members', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->string('relationship_code', 80);
            $table->text('name');
            $table->unsignedSmallInteger('age')->nullable();
            $table->text('school')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->index(['application_id', 'retired_at']);
        });

        Schema::create('application_family_references', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->string('relationship_code', 80);
            $table->text('name');
            $table->text('phone')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->index(['application_id', 'retired_at']);
        });

        Schema::create('application_residences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->text('structured_address');
            $table->string('housing_type_code', 80)->nullable();
            $table->string('tenure_code', 80)->nullable();
            $table->string('financing_code', 80)->nullable();
            $table->text('dimensions')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->index(['application_id', 'retired_at']);
        });

        Schema::create('application_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->text('declared_details');
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->index(['application_id', 'retired_at']);
        });

        Schema::create('application_assets_liabilities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->string('entry_type', 30);
            $table->text('description');
            $table->decimal('amount', 19, 4)->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->index(['application_id', 'entry_type', 'retired_at']);
        });

        Schema::create('application_employments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->text('workplace');
            $table->text('declared_details')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->index(['application_id', 'retired_at']);
        });

        Schema::create('application_labor_references', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->text('name');
            $table->text('contact')->nullable();
            $table->text('declared_details')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->index(['application_id', 'retired_at']);
        });

        Schema::create('application_commercial_credits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->text('company_name');
            $table->decimal('credit_limit', 19, 4)->nullable();
            $table->uuid('proof_media_id')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->index(['application_id', 'retired_at']);
        });

        Schema::create('application_capture_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->string('section', 50);
            $table->uuid('record_public_id')->nullable();
            $table->string('action', 30);
            $table->text('previous_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->timestampTz('recorded_at');
            $table->index(['application_id', 'recorded_at']);
        });

        Schema::create('application_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->unsignedBigInteger('application_version');
            $table->string('snapshot_hash', 64);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at');
            $table->string('idempotency_key', 150);
            $table->unique(['application_id', 'idempotency_key']);
        });

        Schema::create('application_review_observations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->text('observation');
            $table->string('action', 40);
            $table->foreignId('coordinator_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->index(['application_id', 'recorded_at']);
        });

        Schema::create('application_verifier_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->foreignId('verifier_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('assigned_at');
            $table->timestampTz('ended_at')->nullable();
            $table->text('reason')->nullable();
            $table->boolean('active_slot')->nullable()->default(true);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->unique(['application_id', 'active_slot']);
            $table->index(['verifier_user_id', 'active_slot']);
        });

        Schema::create('verification_visits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->foreignId('assignment_id')->unique()->constrained('application_verifier_assignments')->restrictOnDelete();
            $table->foreignId('verifier_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->string('result', 40)->nullable();
            $table->text('observations')->nullable();
            $table->uuid('auth_session_public_id')->nullable();
            $table->text('device_context')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->unique('application_id');
        });

        Schema::create('application_media_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('verification_visits')->restrictOnDelete();
            $table->uuid('media_id');
            $table->string('purpose', 80);
            $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('linked_at');
            $table->unique(['application_id', 'media_id', 'purpose']);
            $table->index(['visit_id', 'purpose']);
        });

        Schema::create('verification_differences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->foreignId('visit_id')->constrained('verification_visits')->restrictOnDelete();
            $table->string('section', 50);
            $table->string('field_path', 255);
            $table->text('declared_value');
            $table->text('observed_value');
            $table->text('description');
            $table->uuid('evidence_media_id')->nullable();
            $table->string('classification_code', 80);
            $table->foreignId('verifier_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->index(['application_id', 'resolved_at']);
        });

        Schema::create('application_corrections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->foreignId('difference_id')->nullable()->constrained('verification_differences')->restrictOnDelete();
            $table->string('section', 50);
            $table->string('field_path', 255);
            $table->text('original_value');
            $table->text('corrected_value');
            $table->text('reason');
            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('corrected_at');
            $table->uuid('request_id');
            $table->index(['application_id', 'corrected_at']);
        });

        Schema::create('application_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->unique()->constrained('distributor_applications')->restrictOnDelete();
            $table->foreignId('coordinator_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 40);
            $table->text('reason');
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->constrained('verification_visits')->restrictOnDelete();
            $table->unsignedBigInteger('application_version');
            $table->timestampTz('decided_at');
        });

        Schema::create('application_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->unique()->constrained('distributor_applications')->restrictOnDelete();
            $table->string('decision', 20);
            $table->decimal('initial_credit_line', 19, 4)->nullable();
            $table->text('reason');
            $table->foreignId('manager_user_id')->constrained('users')->restrictOnDelete();
            $table->string('manager_role', 40);
            $table->foreignId('manager_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->unsignedBigInteger('application_version');
            $table->timestampTz('decided_at');
            $table->string('idempotency_key', 150);
            $table->unique(['application_id', 'idempotency_key']);
        });

        Schema::create('application_activation_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->unique()->constrained('distributor_applications')->restrictOnDelete();
            $table->foreignId('authorization_id')->unique()->constrained('application_authorizations')->restrictOnDelete();
            $table->uuid('distributor_id')->unique();
            $table->string('distributor_number', 80)->unique();
            $table->uuid('account_id')->unique();
            $table->uuid('organization_assignment_id')->unique();
            $table->uuid('credit_line_id')->unique();
            $table->uuid('initial_movement_id')->unique();
            $table->uuid('first_voucher_restriction_id')->unique();
            $table->decimal('initial_credit_line', 19, 4);
            $table->string('operation_key', 150)->unique();
            $table->timestampTz('activated_at');
        });

        Schema::create('application_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 40);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('result', 40)->nullable();
            $table->unsignedBigInteger('application_version');
            $table->string('idempotency_key', 150);
            $table->uuid('request_id');
            $table->timestampTz('occurred_at');
            $table->unique(['application_id', 'idempotency_key']);
            $table->index(['application_id', 'occurred_at']);
        });

        Schema::create('application_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->constrained('distributor_applications')->restrictOnDelete();
            $table->string('event_type', 100);
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('authorizer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('executor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('auth_session_id')->nullable()->constrained('auth_sessions')->restrictOnDelete();
            $table->string('actor_role', 40)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('application_folio', 40);
            $table->string('entity_type', 80);
            $table->uuid('entity_public_id')->nullable();
            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40)->nullable();
            $table->text('protected_previous_value')->nullable();
            $table->text('protected_new_value')->nullable();
            $table->text('reason')->nullable();
            $table->string('result', 40)->nullable();
            $table->unsignedBigInteger('application_version');
            $table->uuid('request_id');
            $table->uuid('trace_id')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->dateTimeTz('business_occurred_at');
            $table->index(['application_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        Schema::create('onboarding_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('distributor_applications')->restrictOnDelete();
            $table->string('operation', 80);
            $table->string('scope_key', 64);
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64);
            $table->string('resource_type', 80)->nullable();
            $table->uuid('resource_public_id')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['operation', 'scope_key', 'idempotency_key']);
        });

        $this->addPostgreSqlConstraints();
    }

    private function addPostgreSqlConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE distributor_applications
                ADD CONSTRAINT distributor_applications_status_check CHECK (
                    status IN (
                        'CAPTURE', 'COORDINATOR_REVIEW', 'VISIT_ASSIGNED',
                        'PHYSICAL_VERIFICATION', 'COORDINATOR_CORRECTION',
                        'COORDINATOR_EVALUATION', 'TERMINATED_UNFAVORABLE',
                        'MANAGER_AUTHORIZATION', 'REJECTED', 'ACTIVE'
                    )
                ),
                ADD CONSTRAINT distributor_applications_result_check CHECK (
                    result IS NULL OR result IN ('TERMINATED_UNFAVORABLE', 'REJECTED', 'ACTIVE')
                ),
                ADD CONSTRAINT distributor_applications_terminal_result_check CHECK (
                    (status IN ('TERMINATED_UNFAVORABLE', 'REJECTED', 'ACTIVE') AND result = status)
                    OR (status NOT IN ('TERMINATED_UNFAVORABLE', 'REJECTED', 'ACTIVE') AND result IS NULL)
                );

            ALTER TABLE application_assets_liabilities
                ADD CONSTRAINT application_assets_liabilities_type_check
                CHECK (entry_type IN ('ASSET', 'LOAN', 'ACTIVE_COMMITMENT')),
                ADD CONSTRAINT application_assets_liabilities_amount_check
                CHECK (amount IS NULL OR amount >= 0);

            ALTER TABLE application_commercial_credits
                ADD CONSTRAINT application_commercial_credits_limit_check
                CHECK (credit_limit IS NULL OR credit_limit >= 0);

            ALTER TABLE application_verifier_assignments
                ADD CONSTRAINT application_verifier_assignments_active_check
                CHECK (
                    (ended_at IS NULL AND active_slot IS TRUE)
                    OR (ended_at IS NOT NULL AND active_slot IS NULL)
                );

            ALTER TABLE verification_visits
                ADD CONSTRAINT verification_visits_result_check
                CHECK (result IS NULL OR result IN ('FAVORABLE', 'UNFAVORABLE', 'CORRECTABLE_DIFFERENCES')),
                ADD CONSTRAINT verification_visits_completion_check
                CHECK (
                    (completed_at IS NULL AND result IS NULL)
                    OR (completed_at IS NOT NULL AND result IS NOT NULL AND observations IS NOT NULL)
                );

            ALTER TABLE application_evaluations
                ADD CONSTRAINT application_evaluations_decision_check
                CHECK (decision IN ('MEETS_REQUIREMENTS', 'DOES_NOT_MEET_REQUIREMENTS'));

            ALTER TABLE application_authorizations
                ADD CONSTRAINT application_authorizations_decision_check
                CHECK (decision IN ('APPROVE', 'REJECT')),
                ADD CONSTRAINT application_authorizations_credit_line_check
                CHECK (
                    (decision = 'APPROVE' AND initial_credit_line IS NOT NULL AND initial_credit_line >= 0)
                    OR (decision = 'REJECT' AND initial_credit_line IS NULL)
                );

            ALTER TABLE application_activation_records
                ADD CONSTRAINT application_activation_records_credit_line_check
                CHECK (initial_credit_line >= 0);

            CREATE OR REPLACE FUNCTION validate_distributor_application_transition() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'CAPTURE' THEN
                        RAISE EXCEPTION 'A distributor application must start in CAPTURE';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.status = OLD.status THEN
                    RETURN NEW;
                END IF;

                IF NOT (
                    (OLD.status = 'CAPTURE' AND NEW.status = 'COORDINATOR_REVIEW')
                    OR (OLD.status = 'COORDINATOR_REVIEW' AND NEW.status IN ('CAPTURE', 'VISIT_ASSIGNED'))
                    OR (OLD.status = 'VISIT_ASSIGNED' AND NEW.status = 'PHYSICAL_VERIFICATION')
                    OR (OLD.status = 'PHYSICAL_VERIFICATION' AND NEW.status IN ('COORDINATOR_CORRECTION', 'COORDINATOR_EVALUATION', 'TERMINATED_UNFAVORABLE'))
                    OR (OLD.status = 'COORDINATOR_CORRECTION' AND NEW.status = 'COORDINATOR_EVALUATION')
                    OR (OLD.status = 'COORDINATOR_EVALUATION' AND NEW.status IN ('TERMINATED_UNFAVORABLE', 'MANAGER_AUTHORIZATION'))
                    OR (OLD.status = 'MANAGER_AUTHORIZATION' AND NEW.status IN ('REJECTED', 'ACTIVE'))
                ) THEN
                    RAISE EXCEPTION 'Invalid distributor application transition from % to %', OLD.status, NEW.status;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER distributor_applications_validate_transition
            BEFORE INSERT OR UPDATE OF status ON distributor_applications
            FOR EACH ROW EXECUTE FUNCTION validate_distributor_application_transition();

            CREATE OR REPLACE FUNCTION protect_completed_verification_visit() RETURNS trigger AS $$
            BEGIN
                IF OLD.completed_at IS NOT NULL AND (
                    NEW.completed_at IS DISTINCT FROM OLD.completed_at
                    OR NEW.result IS DISTINCT FROM OLD.result
                    OR NEW.observations IS DISTINCT FROM OLD.observations
                ) THEN
                    RAISE EXCEPTION 'A completed verification visit is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER verification_visits_protect_completed
            BEFORE UPDATE ON verification_visits
            FOR EACH ROW EXECUTE FUNCTION protect_completed_verification_visit();

            CREATE OR REPLACE FUNCTION prevent_onboarding_deletion() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% cannot be deleted physically', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER distributor_applications_no_delete BEFORE DELETE ON distributor_applications FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_personal_data_no_delete BEFORE DELETE ON application_personal_data FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_family_members_no_delete BEFORE DELETE ON application_family_members FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_family_references_no_delete BEFORE DELETE ON application_family_references FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_residences_no_delete BEFORE DELETE ON application_residences FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_vehicles_no_delete BEFORE DELETE ON application_vehicles FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_assets_liabilities_no_delete BEFORE DELETE ON application_assets_liabilities FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_employments_no_delete BEFORE DELETE ON application_employments FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_labor_references_no_delete BEFORE DELETE ON application_labor_references FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_commercial_credits_no_delete BEFORE DELETE ON application_commercial_credits FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_capture_revisions_no_delete BEFORE DELETE ON application_capture_revisions FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_submissions_no_delete BEFORE DELETE ON application_submissions FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_review_observations_no_delete BEFORE DELETE ON application_review_observations FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_verifier_assignments_no_delete BEFORE DELETE ON application_verifier_assignments FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER verification_visits_no_delete BEFORE DELETE ON verification_visits FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_media_links_no_delete BEFORE DELETE ON application_media_links FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER verification_differences_no_delete BEFORE DELETE ON verification_differences FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_corrections_no_delete BEFORE DELETE ON application_corrections FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_evaluations_no_delete BEFORE DELETE ON application_evaluations FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_authorizations_no_delete BEFORE DELETE ON application_authorizations FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_activation_records_no_delete BEFORE DELETE ON application_activation_records FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_status_histories_no_delete BEFORE DELETE ON application_status_histories FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();
            CREATE TRIGGER application_audits_no_delete BEFORE DELETE ON application_audits FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_deletion();

            CREATE OR REPLACE FUNCTION prevent_onboarding_history_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is immutable', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER application_capture_revisions_no_update BEFORE UPDATE ON application_capture_revisions FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_history_update();
            CREATE TRIGGER application_submissions_no_update BEFORE UPDATE ON application_submissions FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_history_update();
            CREATE TRIGGER application_review_observations_no_update BEFORE UPDATE ON application_review_observations FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_history_update();
            CREATE TRIGGER application_corrections_no_update BEFORE UPDATE ON application_corrections FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_history_update();
            CREATE TRIGGER application_evaluations_no_update BEFORE UPDATE ON application_evaluations FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_history_update();
            CREATE TRIGGER application_authorizations_no_update BEFORE UPDATE ON application_authorizations FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_history_update();
            CREATE TRIGGER application_activation_records_no_update BEFORE UPDATE ON application_activation_records FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_history_update();
            CREATE TRIGGER application_status_histories_no_update BEFORE UPDATE ON application_status_histories FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_history_update();
            CREATE TRIGGER application_audits_no_update BEFORE UPDATE ON application_audits FOR EACH ROW EXECUTE FUNCTION prevent_onboarding_history_update();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_completed_verification_visit() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS validate_distributor_application_transition() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_onboarding_history_update() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_onboarding_deletion() CASCADE');
        }

        Schema::dropIfExists('onboarding_idempotency_keys');
        Schema::dropIfExists('application_audits');
        Schema::dropIfExists('application_status_histories');
        Schema::dropIfExists('application_activation_records');
        Schema::dropIfExists('application_authorizations');
        Schema::dropIfExists('application_evaluations');
        Schema::dropIfExists('application_corrections');
        Schema::dropIfExists('verification_differences');
        Schema::dropIfExists('application_media_links');
        Schema::dropIfExists('verification_visits');
        Schema::dropIfExists('application_verifier_assignments');
        Schema::dropIfExists('application_review_observations');
        Schema::dropIfExists('application_submissions');
        Schema::dropIfExists('application_capture_revisions');
        Schema::dropIfExists('application_commercial_credits');
        Schema::dropIfExists('application_labor_references');
        Schema::dropIfExists('application_employments');
        Schema::dropIfExists('application_assets_liabilities');
        Schema::dropIfExists('application_vehicles');
        Schema::dropIfExists('application_residences');
        Schema::dropIfExists('application_family_references');
        Schema::dropIfExists('application_family_members');
        Schema::dropIfExists('application_personal_data');
        Schema::dropIfExists('distributor_applications');
    }
};
