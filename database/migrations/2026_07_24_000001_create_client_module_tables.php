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
        Schema::create('clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('given_names', 160);
            $table->string('surnames', 200);
            $table->text('curp_ciphertext');
            $table->string('curp_hmac', 64)->unique();
            $table->string('curp_last4', 4);
            $table->text('rfc_ciphertext')->nullable();
            $table->date('birth_date')->nullable();
            $table->text('birth_place_ciphertext')->nullable();
            $table->text('birth_state_ciphertext')->nullable();
            $table->text('birth_city_ciphertext')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('registration_operation_id', 64)->unique();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->index(['given_names', 'surnames', 'created_at']);
        });

        Schema::create('client_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->text('street_ciphertext');
            $table->text('exterior_number_ciphertext');
            $table->text('interior_number_ciphertext')->nullable();
            $table->text('neighborhood_ciphertext');
            $table->text('postal_code_ciphertext');
            $table->text('municipality_ciphertext');
            $table->text('city_ciphertext');
            $table->text('state_ciphertext');
            $table->string('address_fingerprint_hmac', 64);
            $table->string('normalization_version', 20);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->uuid('change_authorization_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->boolean('active_slot')->nullable()->default(true);
            $table->timestampTz('created_at');
            $table->unique(['client_id', 'active_slot']);
            $table->unique(['address_fingerprint_hmac', 'active_slot']);
            $table->index(['client_id', 'effective_from']);
        });

        Schema::create('client_bank_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->text('account_ciphertext');
            $table->string('account_hmac', 64);
            $table->string('account_last4', 4);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->uuid('change_authorization_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->boolean('active_slot')->nullable()->default(true);
            $table->timestampTz('created_at');
            $table->unique(['client_id', 'active_slot']);
            $table->index(['client_id', 'effective_from']);
        });

        Schema::create('client_distributor_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->uuid('distributor_id');
            $table->foreignId('branch_id_snapshot')->constrained('branches')->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->string('assignment_type', 40);
            $table->uuid('mobility_operation_id')->nullable()->unique();
            $table->string('mobility_request_hash', 64)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('active_slot')->nullable()->default(true);
            $table->timestampTz('created_at');
            $table->unique(['client_id', 'active_slot']);
            $table->index(['distributor_id', 'active_slot', 'created_at']);
            $table->index(['branch_id_snapshot', 'active_slot', 'created_at']);
        });

        Schema::create('client_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('document_type', 50);
            $table->uuid('media_id');
            $table->string('file_fingerprint', 128)->nullable();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->uuid('replaced_document_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->boolean('active_slot')->nullable()->default(true);
            $table->timestampTz('created_at');
            $table->unique(['client_id', 'document_type', 'active_slot'], 'client_documents_current_unique');
            $table->index(['client_id', 'document_type', 'effective_from']);
        });
        Schema::table('client_documents', function (Blueprint $table): void {
            $table->foreign('replaced_document_id')
                ->references('id')
                ->on('client_documents')
                ->restrictOnDelete();
        });

        Schema::create('client_portfolio_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->uuid('distributor_id');
            $table->foreignUuid('assignment_id')->unique()->constrained('client_distributor_assignments')->restrictOnDelete();
            $table->boolean('tracking_enabled')->default(false);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['client_id', 'distributor_id']);
        });

        Schema::create('client_portfolio_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->uuid('distributor_id');
            $table->foreignUuid('assignment_id')->constrained('client_distributor_assignments')->restrictOnDelete();
            $table->uuid('voucher_id')->nullable()->unique();
            $table->string('entry_type', 30);
            $table->decimal('amount', 19, 4)->nullable();
            $table->string('informational_status', 20);
            $table->date('occurred_on');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->unique(['distributor_id', 'idempotency_key']);
            $table->index(['client_id', 'created_at']);
            $table->index(['assignment_id', 'created_at']);
        });

        Schema::create('client_portfolio_entry_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('entry_id')->constrained('client_portfolio_entries')->restrictOnDelete();
            $table->text('previous_note')->nullable();
            $table->text('new_note')->nullable();
            $table->string('previous_status', 20);
            $table->string('new_status', 20);
            $table->unsignedBigInteger('previous_version');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');
            $table->index(['entry_id', 'changed_at']);
        });

        Schema::create('client_portfolio_confirmations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->uuid('distributor_id');
            $table->foreignUuid('assignment_id')->constrained('client_distributor_assignments')->restrictOnDelete();
            $table->decimal('total_balance', 19, 4);
            $table->decimal('overdue_balance', 19, 4)->nullable();
            $table->unsignedBigInteger('portfolio_version');
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at');
            $table->uuid('operation_id')->unique();
            $table->index(['client_id', 'confirmed_at']);
        });

        Schema::create('client_change_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->uuid('authorization_id');
            $table->uuid('operation_id')->unique();
            $table->string('request_hash', 64);
            $table->jsonb('changed_fields');
            $table->text('protected_previous_values');
            $table->text('protected_new_values');
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->timestampTz('changed_at');
        });

        Schema::create('client_registration_idempotency', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64);
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['actor_user_id', 'idempotency_key']);
        });

        Schema::create('client_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('event_type', 100);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('auth_session_id')->nullable()->constrained('auth_sessions')->restrictOnDelete();
            $table->string('actor_role', 40)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->uuid('distributor_id')->nullable();
            $table->uuid('related_operation_id')->nullable();
            $table->jsonb('changed_fields')->nullable();
            $table->text('protected_previous_value')->nullable();
            $table->text('protected_new_value')->nullable();
            $table->text('reason')->nullable();
            $table->string('result', 30);
            $table->uuid('request_id');
            $table->string('ip_hash', 64)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['client_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        $this->addPostgreSqlConstraints();
    }

    private function addPostgreSqlConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE client_distributor_assignments
                ADD CONSTRAINT client_assignment_type_check
                CHECK (assignment_type IN ('INITIAL', 'AUTHORIZED_TRANSFER'));
            ALTER TABLE client_portfolio_entries
                ADD CONSTRAINT client_portfolio_entry_type_check
                CHECK (entry_type IN ('VOUCHER', 'PAYMENT', 'INSTALLMENT', 'STATUS_UPDATE', 'NOTE')),
                ADD CONSTRAINT client_portfolio_status_check
                CHECK (informational_status IN ('PENDING', 'PARTIAL', 'PAID')),
                ADD CONSTRAINT client_portfolio_amount_check
                CHECK (
                    (entry_type IN ('VOUCHER', 'PAYMENT', 'INSTALLMENT') AND amount > 0)
                    OR (entry_type IN ('STATUS_UPDATE', 'NOTE') AND amount IS NULL)
                );
            ALTER TABLE client_documents
                ADD CONSTRAINT client_document_type_check
                CHECK (document_type IN ('OFFICIAL_IDENTIFICATION', 'ADDRESS_PROOF'));
            CREATE OR REPLACE FUNCTION prevent_client_immutable_history_change()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'M06 history is immutable';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER client_audits_immutable
                BEFORE UPDATE OR DELETE ON client_audits
                FOR EACH ROW EXECUTE FUNCTION prevent_client_immutable_history_change();
            CREATE TRIGGER client_change_history_immutable
                BEFORE UPDATE OR DELETE ON client_change_history
                FOR EACH ROW EXECUTE FUNCTION prevent_client_immutable_history_change();
            CREATE TRIGGER client_portfolio_revisions_immutable
                BEFORE UPDATE OR DELETE ON client_portfolio_entry_revisions
                FOR EACH ROW EXECUTE FUNCTION prevent_client_immutable_history_change();
            CREATE TRIGGER client_portfolio_confirmations_immutable
                BEFORE UPDATE OR DELETE ON client_portfolio_confirmations
                FOR EACH ROW EXECUTE FUNCTION prevent_client_immutable_history_change();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_client_immutable_history_change() CASCADE');
        }
        Schema::dropIfExists('client_audits');
        Schema::dropIfExists('client_registration_idempotency');
        Schema::dropIfExists('client_change_history');
        Schema::dropIfExists('client_portfolio_confirmations');
        Schema::dropIfExists('client_portfolio_entry_revisions');
        Schema::dropIfExists('client_portfolio_entries');
        Schema::dropIfExists('client_portfolio_settings');
        Schema::dropIfExists('client_documents');
        Schema::dropIfExists('client_distributor_assignments');
        Schema::dropIfExists('client_bank_accounts');
        Schema::dropIfExists('client_addresses');
        Schema::dropIfExists('clients');
    }
};
