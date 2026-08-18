<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS client_number_seq START WITH 1 INCREMENT BY 1');
        }

        Schema::create('clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('client_number', 32)->unique();
            $table->string('first_name');
            $table->string('first_last_name');
            $table->string('second_last_name')->nullable();
            $table->text('curp_ciphertext');
            $table->char('curp_hmac', 64)->unique();
            $table->text('rfc_ciphertext')->nullable();
            $table->char('rfc_hmac', 64)->nullable();
            $table->date('birth_date');
            $table->string('birth_place');
            $table->string('birth_state');
            $table->string('birth_city');
            $table->string('official_id_type', 32);
            $table->text('official_id_number_ciphertext')->nullable();
            $table->char('official_id_number_hmac', 64)->nullable();
            $table->foreignUuid('official_id_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['first_last_name', 'first_name']);
            $table->index('rfc_hmac');
        });

        Schema::create('client_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->boolean('is_current')->default(true);
            $table->string('street');
            $table->string('exterior_number');
            $table->string('interior_number')->nullable();
            $table->string('neighborhood');
            $table->string('postal_code', 10);
            $table->string('municipality');
            $table->string('city');
            $table->string('state');
            $table->char('country', 2)->default('MX');
            $table->char('normalized_fingerprint_hmac', 64);
            $table->foreignUuid('address_proof_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('change_reason')->nullable();
            $table->timestampsTz();

            $table->index(['client_id', 'starts_at']);
        });

        Schema::create('client_bank_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('bank_name');
            $table->string('account_holder_name');
            $table->text('account_number_ciphertext')->nullable();
            $table->char('account_number_hmac', 64)->nullable();
            $table->text('clabe_ciphertext');
            $table->char('clabe_hmac', 64);
            $table->boolean('is_current')->default(true);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('change_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['client_id', 'starts_at']);
            $table->index('account_number_hmac');
            $table->index('clabe_hmac');
        });

        Schema::create('client_distributor_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->foreignUuid('assigned_by')->constrained('users')->restrictOnDelete();
            $table->string('reason')->nullable();
            $table->timestampsTz();

            $table->index(['distributor_id', 'starts_at', 'ends_at']);
            $table->index(['branch_id', 'starts_at', 'ends_at']);
        });

        Schema::create('client_portfolio_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->string('entry_type', 32);
            $table->decimal('amount', 18, 4)->nullable();
            $table->string('informational_status', 24)->nullable();
            $table->timestampTz('occurred_at');
            $table->date('due_date')->nullable();
            $table->timestampTz('last_payment_at')->nullable();
            $table->text('note')->nullable();
            $table->uuid('related_voucher_id')->nullable();
            $table->foreignUuid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['client_id', 'occurred_at']);
            $table->index(['distributor_id', 'occurred_at']);
        });

        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_official_id_type_check CHECK (official_id_type IN ('INE', 'PASSPORT', 'PROFESSIONAL_LICENSE', 'OTHER'))");
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_lock_version_check CHECK (lock_version >= 1)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_number_check CHECK (client_number ~ '^CLI-[0-9]{4}-[0-9]{6,}$')");
        }
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE client_addresses ADD CONSTRAINT client_addresses_dates_check CHECK (ends_at IS NULL OR ends_at > starts_at)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE client_bank_accounts ADD CONSTRAINT client_bank_accounts_dates_check CHECK (ends_at IS NULL OR ends_at > starts_at)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE client_bank_accounts ADD CONSTRAINT client_bank_accounts_lock_version_check CHECK (lock_version >= 1)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE client_distributor_assignments ADD CONSTRAINT client_distributor_assignments_dates_check CHECK (ends_at IS NULL OR ends_at > starts_at)');
        }
        DB::statement("ALTER TABLE client_portfolio_entries ADD CONSTRAINT client_portfolio_entries_type_check CHECK (entry_type IN ('DEBT', 'PAYMENT', 'PARTIAL_PAYMENT', 'STATUS_UPDATE', 'NOTE', 'ADJUSTMENT_INCREASE', 'ADJUSTMENT_DECREASE'))");
        DB::statement("ALTER TABLE client_portfolio_entries ADD CONSTRAINT client_portfolio_entries_status_check CHECK (informational_status IS NULL OR informational_status IN ('PENDING', 'PARTIALLY_PAID', 'PAID'))");
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE client_portfolio_entries ADD CONSTRAINT client_portfolio_entries_amount_check CHECK (amount IS NULL OR amount > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE client_portfolio_entries ADD CONSTRAINT client_portfolio_entries_lock_version_check CHECK (lock_version >= 1)');
        }

        DB::statement('CREATE UNIQUE INDEX client_addresses_current_client_unique ON client_addresses (client_id) WHERE ends_at IS NULL AND is_current = true');
        DB::statement('CREATE UNIQUE INDEX client_addresses_current_fingerprint_unique ON client_addresses (normalized_fingerprint_hmac) WHERE ends_at IS NULL AND is_current = true');
        DB::statement('CREATE UNIQUE INDEX client_bank_accounts_current_client_unique ON client_bank_accounts (client_id) WHERE ends_at IS NULL AND is_current = true');
        DB::statement('CREATE UNIQUE INDEX client_distributor_assignments_current_client_unique ON client_distributor_assignments (client_id) WHERE ends_at IS NULL');

        $this->crearProteccionContraEliminacion();
    }

    public function down(): void
    {
        foreach (['client_portfolio_entries', 'client_distributor_assignments', 'client_bank_accounts', 'client_addresses', 'clients'] as $table) {
            if (DB::getDriverName() !== 'sqlite') {
            DB::statement("DROP TRIGGER IF EXISTS trg_prevent_{$table}_deletion ON {$table}");
            DB::statement("DROP FUNCTION IF EXISTS prevent_{$table}_deletion()");
        }
        }

        Schema::dropIfExists('client_portfolio_entries');
        Schema::dropIfExists('client_distributor_assignments');
        Schema::dropIfExists('client_bank_accounts');
        Schema::dropIfExists('client_addresses');
        Schema::dropIfExists('clients');
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('DROP SEQUENCE IF EXISTS client_number_seq');
        }
    }

    private function crearProteccionContraEliminacion(): void
    {
        foreach (['clients', 'client_addresses', 'client_bank_accounts', 'client_distributor_assignments', 'client_portfolio_entries'] as $table) {
            if (DB::getDriverName() !== 'sqlite') {
            DB::statement(<<<SQL
                CREATE OR REPLACE FUNCTION prevent_{$table}_deletion()
                RETURNS trigger AS \$\$
                BEGIN
                    RAISE EXCEPTION 'Los registros del mÃ³dulo de clientes no se eliminan fÃ­sicamente.';
                END;
                \$\$ LANGUAGE plpgsql
            SQL);
            DB::statement("CREATE TRIGGER trg_prevent_{$table}_deletion BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_{$table}_deletion()");
        }
        }
    }
};

